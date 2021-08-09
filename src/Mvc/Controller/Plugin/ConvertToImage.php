<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use finfo;
use IiifServer\Mvc\Controller\Plugin\ImageSize;
use ImageServer\ImageServer\AbstractImager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class ConvertToImage extends AbstractPlugin
{
    /**
     * @var \ImageServer\ImageServer\AbstractImager
     */
    protected $imager;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\ImageSize
     */
    protected $imageSize;

    public function __construct(AbstractImager $imager, ImageSize $imageSize)
    {
        $this->imager = $imager;
        $this->imageSize = $imageSize;
    }

    /**
     * Convert an asset into another format.
     */
    public function __invoke(string $source, string $destination, array $params = []): bool
    {
        // TODO Support media types other than the iiif prelisted ones.
        $mediaTypeDestination = $params['media_type'] ?? null;
        if (!$mediaTypeDestination || !$this->imager->checkMediaType($mediaTypeDestination)) {
            return false;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaTypeSource = strtolower($finfo->file($source));
        if (substr($mediaTypeSource, 0, 6) !== 'image/' && $mediaTypeSource !== 'application/pdf') {
            return false;
        }

        if (!$this->imager->checkMediaType($mediaTypeSource)) {
            return false;
        }

        list($width, $height) = array_values($this->imageSize->__invoke($source));
        if (!$width || !$height) {
            return false;
        }

        // The convert is done even for a same format source for simplicity and
        // to manage the case that the source is not optimized for tiling.
        $args = [
            'source' => [
                'type' => 'original',
                'filepath' => $source,
                'media_type' => $mediaTypeSource,
                'width' => $width,
                'height' => $height,
            ],
            'version' => '3',
            'region' => [
                'feature' => 'full',
                'x' => 0,
                'y' => 0,
                'width' => $width,
                'height' => $height,
            ],
            'size' => [
                'feature' => 'max',
            ],
            'mirror' => [
                'feature' => 'default',
            ],
            'rotation' => [
                'feature' => 'noRotation',
            ],
            'quality' => [
                'feature' => 'default',
            ],
            'format' => [
                'feature' => $mediaTypeDestination,
            ],
            'destination' => [
                'filepath' => $destination,
                'options' => $mediaTypeDestination,
            ],
        ];

        $result = $this->imager->transform($args);

        return !empty($result);
    }
}
