<?php
namespace ImageServer\Mvc\Controller\Plugin;

use Omeka\Api\Adapter\Manager as AdapterManager;
use Omeka\Api\Representation\AssetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\Asset;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class ImageSize extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var AdapterManager
     */
    protected $adapterManager;

    /**
     * @param string $basePath
     * @param TempFileFactory $tempFileFactory
     * @param AdapterManager $adapterManager
     */
    public function __construct(
        $basePath,
        TempFileFactory $tempFileFactory,
        AdapterManager $adapterManager
    ) {
        $this->basePath = $basePath;
        $this->tempFileFactory = $tempFileFactory;
        $this->adapterManager = $adapterManager;
    }

    /**
     * Get an array of the width and height of the image file from a media.
     *
     * If media is not an image, width and height are null.
     *
     * @see \IiifServer\Mvc\Controller\Plugin\ImageSize
     *
     * @param MediaRepresentation|AssetRepresentation|Media|Asset|string $image
     * Can be a media, an asset, a url or a filepath.
     * @param string $imageType
     * @return array Associative array of width and height of the image file.
     * Values are empty when the size is undetermined.
     */
    public function __invoke($image, $imageType = 'original')
    {
        if ($image instanceof MediaRepresentation) {
            return $this->sizeMedia($image, $imageType);
        }
        if ($image instanceof AssetRepresentation) {
            return $this->sizeAsset($image);
        }
        if ($image instanceof Media) {
            $image = $this->adapterManager->get('media')->getRepresentation($image);
            return $this->sizeMedia($image, $imageType);
        }
        if ($image instanceof Asset) {
            $image = $this->adapterManager->get('assets')->getRepresentation($image);
            return $this->sizeAsset($image);
        }
        return $this->getWidthAndHeight($image);
    }

    /**
     * Get an array of the width and height of the image file from a media.
     *
     * @param MediaRepresentation $media
     * @param string $imageType
     * @return array Associative array of width and height of the image file.
     * Values are empty when the size is undetermined.
     */
    protected function sizeMedia(MediaRepresentation $media, $imageType = 'original')
    {
        // Check if this is an image.
        if (strtok($media->mediaType(), '/') !== 'image') {
            return ['width' => null, 'height' => null];
        }

        // Check if size is already stored. The stored dimension may be null.
        $mediaData = $media->mediaData();
        if (is_array($mediaData)
            && !empty($mediaData['dimensions'][$imageType])
        ) {
            return $mediaData['dimensions'][$imageType];
        }

        // The storage adapter should be checked for external storage.
        $storagePath = $imageType == 'original'
            ? $this->getStoragePath($imageType, $media->filename())
            : $this->getStoragePath($imageType, $media->storageId(), 'jpg');
        $filepath = $this->basePath . DIRECTORY_SEPARATOR . $storagePath;
        return $this->getWidthAndHeight($filepath);
    }

    /**
     * Get an array of the width and height of the image file from an asset.
     *
     * @param AssetRepresentation $asset
     * @return array Associative array of width and height of the image file.
     * Values are empty when the size is undetermined.
     */
    protected function sizeAsset(AssetRepresentation $asset)
    {
        // The storage adapter should be checked for external storage.
        $storagePath = $this->getStoragePath('asset', $asset->filename());
        $filepath = $this->basePath . DIRECTORY_SEPARATOR . $storagePath;
        return $this->getWidthAndHeight($filepath);
    }

    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix
     * @param string $name The file name, or basename if extension is passed
     * @param null|string $extension The file extension
     * @return string
     */
    protected function getStoragePath($prefix, $name, $extension = '')
    {
        return sprintf('%s/%s%s', $prefix, $name, strlen($extension) ? '.' . $extension : '');
    }

    /**
     * Helper to get width and height of an image, local or remote.
     *
     * @param string $filepath This should be an image (no check here).
     * @return array Associative array of width and height of the image file.
     * Values are empty when the size is undetermined.
     */
    protected function getWidthAndHeight($filepath)
    {
        // An internet path.
        $width = null;
        $height = null;
        if (strpos($filepath, 'https://') === 0 || strpos($filepath, 'http://') === 0) {
            $tempFile = $this->tempFileFactory->build();
            $tempPath = $tempFile->getTempPath();
            $tempFile->delete();
            $handle = @fopen($filepath, 'rb');
            if ($handle) {
                $result = file_put_contents($tempPath, $handle);
                @fclose($handle);
                if ($result) {
                    $result = getimagesize($tempPath);
                    if ($result) {
                        list($width, $height) = $result;
                    }
                }
                unlink($tempPath);
            }
        }
        // A normal path.
        elseif (file_exists($filepath)) {
            $result = getimagesize($filepath);
            if ($result) {
                list($width, $height) = $result;
            }
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }
}
