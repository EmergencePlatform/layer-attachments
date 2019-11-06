<?php

namespace Emergence\Attachments;

use Imagick;
use ImagickPixel;
use RuntimeException;

use Emergence\Site\Storage;


class RequestHandler extends \RecordsRequestHandler
{
    public static $imageStorageBucketId = 'attachment-images';
    public static $imageCompressionQuality = 90;

    public static $recordClass = Attachment::class;
    public static $accountLevelAPI = 'ReadOnlyStaff';
    public static $accountLevelBrowse = 'ReadOnlyStaff';
    public static $accountLevelRead = 'ReadOnlyStaff';
    public static $accountLevelComment = 'Staff';
    public static $accountLevelWrite = 'Staff';
    public static $browseLimitDefault = 20;
    public static $browseOrder = ['ID' => 'DESC'];

    public static function handleRecordRequest(\ActiveRecord $Attachment, $action = false)
    {
        switch ($action ?: $action = static::shiftPath()) {
            case 'content':
                return static::handleContentRequest($Attachment);
            case 'image':
                return static::handleImageRequest($Attachment);
            default:
                return parent::handleRecordRequest($Attachment, $action);
        }
    }

    public static function handleContentRequest(Attachment $Attachment)
    {
        // check if configured
        if (!$Attachment->ContentHash) {
            return static::throwNotFoundError('attachment has no content yet');
        }

        // content should be immutable, so respond 304 right away if possible
        if (
            !empty($_SERVER['HTTP_IF_NONE_MATCH'])
            && $_SERVER['HTTP_IF_NONE_MATCH'] == $Attachment->ContentHash
        ) {
            header('HTTP/1.0 304 Not Modified');
            return;
        }

        $expires = 60*60*24*365;
        header('Cache-Control: public, max-age='.$expires);
        header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time()+$expires));
        header('Pragma: public');
        header('Content-Type: '.$Attachment->MIMEType);
        header('ETag: '.$Attachment->ContentHash);

        $storageStream = $Attachment->readStream();
        fpassthru($storageStream);
        fclose($storageStream);
    }

    public static function handleImageRequest(Attachment $Attachment)
    {
        // check if configured
        if (!$Attachment->ContentHash) {
            return static::throwNotFoundError('attachment has no content yet');
        }


        // parse request
        $maxWidth = static::shiftPath() ?: null;
        $maxHeight = static::shiftPath() ?: $maxWidth;



        // construct construct storage path
        if ($maxWidth || $maxHeight) {
            $storagePath = sprintf('%u/%u/%s', $maxWidth, $maxHeight, $Attachment->ContentHash);
        } else {
            $storagePath = sprintf('full/%s', $Attachment->ContentHash);
        }


        // URLs should be immutable, so respond 304 right away if possible
        if (
            (
                !empty($_SERVER['HTTP_IF_NONE_MATCH'])
                && $_SERVER['HTTP_IF_NONE_MATCH'] == $storagePath
            )
            || !empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])
        ) {
            header('HTTP/1.0 304 Not Modified');
            return;
        }


        // load from storage
        $storageBucket = Storage::getFilesystem(static::$imageStorageBucketId);

        if (
            $storageBucket->has($storagePath)
            && $imageStream = $storageBucket->readStream($storagePath)
        ) {
            $image = new Imagick();
            $image->readImageFile($imageStream);
        } else {

            // load and configure image
            $image = $Attachment->getImage();
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE); // TODO: see what this do to pngs
            $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN); // TODO: see what this do to things


            // normalize orientation
            switch ($image->getImageOrientation()) {
                case Imagick::ORIENTATION_TOPLEFT:
                    break;
                case Imagick::ORIENTATION_TOPRIGHT:
                    $image->flopImage();
                    break;
                case Imagick::ORIENTATION_BOTTOMRIGHT:
                    $image->rotateImage(new ImagickPixel, 180);
                    break;
                case Imagick::ORIENTATION_BOTTOMLEFT:
                    $image->flopImage();
                    $image->rotateImage(new ImagickPixel, 180);
                    break;
                case Imagick::ORIENTATION_LEFTTOP:
                    $image->flopImage();
                    $image->rotateImage(new ImagickPixel, -90);
                    break;
                case Imagick::ORIENTATION_RIGHTTOP:
                    $image->rotateImage(new ImagickPixel, 90);
                    break;
                case Imagick::ORIENTATION_RIGHTBOTTOM:
                    $image->flopImage();
                    $image->rotateImage(new ImagickPixel, 90);
                    break;
                case Imagick::ORIENTATION_LEFTBOTTOM:
                    $image->rotateImage(new ImagickPixel, -90);
                    break;
                default: // Invalid orientation
                    break;
            }
            $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);


            // scale image if needed
            if ($maxWidth || $maxHeight) {
                $srcWidth = $image->getImageWidth();
                $srcHeight = $image->getImageHeight();

                $widthRatio = ($srcWidth > $maxWidth) ? ($maxWidth / $srcWidth) : 1;
                $heightRatio = ($srcHeight > $maxHeight) ? ($maxHeight / $srcHeight) : 1;

                $ratio = min($widthRatio, $heightRatio);

                if ($ratio < 1) {
                    $scaledWidth = round($srcWidth * $ratio);
                    $scaledHeight = round($srcHeight * $ratio);

                    $image->resizeImage($scaledWidth, $scaledHeight, Imagick::FILTER_LANCZOS, 1);
                }
            }


            // strip metadata but preserve ICC metadata
            $imageProfiles = $image->getImageProfiles('icc', true);
            $image->stripImage();

            if ($imageProfiles && !empty($imageProfiles['icc'])) {
                $image->profileImage('icc', $imageProfiles['icc']);
            }


            // determine output based on type
            switch (strtoupper($image->getImageFormat())) {
                case 'PDF':
                case 'EPS':
                case 'PS':
                    $image->setImageFormat('PNG');
                    break;
                case 'JPEG':
                    $image->setCompressionQuality(static::$imageCompressionQuality);
                    $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                    break;
                case 'GIF':
                    break;
                default:
                    // default everything else to PNG
                    $image->setImageFormat('PNG');
                    break;
            }


            // write to storage
            $imageStream = fopen('php://temp', 'w+');
            $image->writeImageFile($imageStream);
            if (!$storageBucket->writeStream($storagePath, $imageStream)) {
                throw new RuntimeException('unable to save image to');
            }
        }


        // send caching headers
        $expires = 60*60*24*365;
        header('Cache-Control: public, max-age='.$expires);
        header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time()+$expires));
        header('Pragma: public');
        header('ETag: '.$storagePath);


        // write to output
        header('Content-Type: '.$image->getImageMimeType());
        rewind($imageStream);
        fpassthru($imageStream);
        fclose($imageStream);
    }
}
