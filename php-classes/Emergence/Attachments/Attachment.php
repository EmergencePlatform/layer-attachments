<?php

namespace Emergence\Attachments;

use RuntimeException;
use UnexpectedValueException;
use Imagick;

use Media;

use Emergence\Site\Storage;

class Attachment
    extends \VersionedRecord
    implements \Emergence\Interfaces\Image
{
    public static $storageBucketId = 'attachments';

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


        // parse type with ImageMagick
        $image = new Imagick($filePath);

        if (!$image->valid()) {
            throw new UnexpectedValueException('media file is not a handleable format');
        }

        $mimeType = $image->getImageMimeType();


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


        // load from storage bucket
        $image = new Imagick();
        $storageStream = $this->readStream();
        $image->readImageFile($storageStream);
        fclose($storageStream);

        if (!$image->valid()) {
            throw new UnexpectedValueException('image could not be read from attachment content');
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
