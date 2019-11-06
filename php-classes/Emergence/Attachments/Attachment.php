<?php

namespace Emergence\Attachments;

use RuntimeException;
use UnexpectedValueException;
use Imagick;
use ImagickException;

use File;
use Media;

use Emergence\Site\Storage;

class Attachment
    extends \VersionedRecord
    implements \Emergence\Interfaces\Image
{
    public static $storageBucketId = 'attachments';
    public static $fallbackIdenticon = 50;

    public static $collectionRoute = '/attachments';
    public static $tableName = 'attachments';
    public static $singularNoun = 'attachment';
    public static $pluralNoun = 'attachments';

    public static $fields = [
        'ContextClass',
        'ContextID' => 'uint',
        'ContentHash' => [
            'type' => 'string',
            'length' => 40
        ],
        'MIMEType',
        'Title' => [
            'default' => null
        ],
        'Status' => [
            'type' => 'enum',
            'values' => ['normal', 'removed'],
            'default' => 'normal'
        ]
    ];

    // public static $indexes = [
    //     'Context' => [
    //         'fields' => ['ContextClass', 'ContextID']
    //     ]
    // ];

    public static $validators = [
        'Context' => [
            'validator' => 'require-relationship'
        ]
    ];

    public static $relationships = [
        'Context' => [
            'type' => 'context-parent'
        ]
    ];

    public static function createFromFile($filePath, array $fields = [], $autoSave = false)
    {
        $Attachment = static::create($fields);

        $Attachment->loadContent($filePath);

        if ($autoSave) {
            $Attachment->save();
        }

        return $Attachment;
    }

    public static function createFromUpload($uploadData, array $fields = [], $autoSave = false)
    {
        // sanity check
        if (!is_uploaded_file($uploadData['tmp_name'])) {
            throw new RuntimeException('Supplied file is not a valid upload');
        }

        $Attachment = static::create($fields);

        if (!empty($uploadData['name']) && !$Attachment->Title) {
            $Attachment->Title = preg_replace('/\.[^.]+$/', '', $uploadData['name']);
        }

        $Attachment->loadContent($uploadData['tmp_name']);

        if ($autoSave) {
            $Attachment->save();
        }

        return $Attachment;
    }

    public static function createFromMedia(Media $Media, array $fields = [], $autoSave = false)
    {
        // manually create new instance so that CreatorID/Created can be set manually
        $Attachment = new static(
            [
                'Created' => date('Y-m-d H:i:s', $Media->Created),
                'CreatorID' => $Media->CreatorID
            ],
            true,
            true
        );

        $Attachment->Context = $Media->Context;
        $Attachment->Title = $Media->Caption;
        $Attachment->setFields($fields);

        $Attachment->loadContent($Media->FilesystemPath);

        if ($autoSave) {
            $Attachment->save();
        }

        return $Attachment;
    }

    public function destroy()
    {
        $this->Status = 'removed';
        $this->save();
    }

    public function loadContent($filePath)
    {
        // only allow if no content loaded yet
        if ($this->ContentHash) {
            throw new RuntimeException('cannot load content into attachment that already has content hash');
        }


        // try to parse type with ImageMagick first
        try {
            $image = new Imagick($filePath);

            if (!$image->valid()) {
                throw new UnexpectedValueException('attachment content reported as invalid by imagemagick');
            }

            $mimeType = $image->getImageMimeType();
        } catch (ImagickException $e) {
            $mimeType = File::getMIMEType($filePath);
        }


        // calculate content hash via git method
        $contentHash = exec('git hash-object '.escapeshellarg($filePath));


        // upload to storage bucket
        $bucket = Storage::getFilesystem(static::$storageBucketId);
        $storagePath = "uploads/{$contentHash}";

        if (!$bucket->has($storagePath)) {
            $stream = fopen($filePath, 'r');
            $uploadSuccessful = $bucket->writeStream($storagePath, $stream, [
                'ContentType' => $mimeType
            ]);
            fclose($stream);

            if (!$uploadSuccessful) {
                throw new RuntimeException('failed to upload attachment to storage');
            }
        }


        // write to model
        $this->ContentHash = $contentHash;
        $this->MIMEType = $mimeType;
    }

    public function readStream()
    {
        $bucket = Storage::getFilesystem(static::$storageBucketId);
        $storagePath = "uploads/{$this->ContentHash}";

        if (!$bucket->has($storagePath)) {
            throw new RuntimeException('attachment content not available in bucket: '.$storagePath);
        }

        if (!$storageStream = $bucket->readStream($storagePath)) {
            throw new RuntimeException('failed to read attachment content from bucket: '.$storagePath);
        }

        return $storageStream;
    }

    public function getImage(array $options = [])
    {
        if (!$this->ContentHash) {
            return null;
        }


        // try to load image from storage bucket
        $image = new Imagick();
        try {
            $storageStream = $this->readStream();
            $image->readImageFile($storageStream);
            fclose($storageStream);

            if (!$image->valid()) {
                throw new UnexpectedValueException('attachment content reported as invalid by imagemagick');
            }
        } catch (ImagickException $e) {
            if (static::$fallbackIdenticon) {
                // render an identicon instead
                $identicon = new \Jdenticon\Identicon();
                $identicon->setValue($this->ContentHash);
                $identicon->setSize(static::$fallbackIdenticon);
                $image->readImageBlob($identicon->getImageData('png'));
            } else {
                // create minimal PNG
                $image->newImage(1, 1, new ImagickPixel());
                $image->setImageFormat('png');
            }
        }


        // apply any type-specific changes
        switch ($this->MIMEType) {
            case 'application/pdf':
                $image->setResolution(300, 300);
                break;
        }


        // return Imagick intance
        return $image;
    }

    public function getImageUrl($maxWidth = null, $maxHeight = null, array $options = [])
    {
        if ($maxWidth || $maxHeight) {
            $path = sprintf('image/%u/%u', $maxWidth ?: $maxHeight, $maxHeight ?: $maxWidth);
        } else {
            $path = 'image';
        }

        return $this->getUrl($path);
    }
}
