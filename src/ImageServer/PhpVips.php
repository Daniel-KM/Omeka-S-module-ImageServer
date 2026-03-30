<?php declare(strict_types=1);

namespace ImageServer\ImageServer;

use Omeka\File\Store\StoreInterface;
use Omeka\File\TempFileFactory;

/**
 * Image processor using the php-vips library (jcupitt/vips).
 *
 * Faster than the CLI imager because it avoids shell process spawning
 * and intermediate .v files. Requires ext-vips (v1) or ext-ffi (v2).
 */
class PhpVips extends AbstractImager
{
    protected $supportedFormats = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/tiff' => 'tif',
        'image/gif' => 'gif',
        'image/jp2' => 'jp2',
        'image/webp' => 'webp',
    ];

    /**
     * @var bool
     */
    protected $isAvailable = false;

    public function __construct(
        TempFileFactory $tempFileFactory,
        StoreInterface $store
    ) {
        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;

        // jcupitt/vips v1 requires ext-vips, v2 requires ext-ffi.
        // The class may exist (Composer autoload) but fail at runtime
        // without the underlying extension. Test with a real call.
        if (class_exists('Jcupitt\Vips\Image')) {
            try {
                \Jcupitt\Vips\Image::black(1, 1);
                $this->isAvailable = true;
            } catch (\Throwable $e) {
                $this->supportedFormats = [];
            }
        } else {
            $this->supportedFormats = [];
        }
    }

    public function transform(array $args): ?string
    {
        if (!count($args) || !$this->isAvailable) {
            return null;
        }

        $this->args = $args;
        $args = &$this->args;

        if (!$this->checkMediaType($args['source']['media_type'])
            || !$this->checkMediaType($args['format']['feature'])
        ) {
            return null;
        }

        $source = $this->_loadImageResource($args['source']['filepath']);
        if (empty($source)) {
            return null;
        }

        // Get width and height if missing.
        if (empty($args['source']['width']) || empty($args['source']['height'])) {
            [$args['source']['width'], $args['source']['height']] = getimagesize($source);
            try {
                $exif = @exif_read_data($source);
            } catch (\Throwable $e) {
                $exif = false;
            }
            if ($exif && !empty($exif['Orientation']) && $exif['Orientation'] >= 5) {
                [$args['source']['width'], $args['source']['height']]
                    = [$args['source']['height'], $args['source']['width']];
            }
        }

        $extraction = $this->_prepareExtraction();
        if (!$extraction) {
            $this->_destroyIfFetched($source);
            return null;
        }

        [
            $sourceX,
            $sourceY,
            $sourceWidth,
            $sourceHeight,
            $destinationWidth,
            $destinationHeight,
        ] = $extraction;

        $destination = $this->prepareDestinationPath();
        if (!$destination) {
            $this->_destroyIfFetched($source);
            return null;
        }

        try {
            $image = \Jcupitt\Vips\Image::newFromFile(
                $source . '[0]',
                ['access' => 'sequential']
            );

            // Auto-orient (EXIF rotation).
            $image = $image->autorot();

            // Region.
            if ($sourceWidth !== $args['source']['width']
                || $sourceHeight !== $args['source']['height']
            ) {
                $image = $image->extract_area(
                    $sourceX, $sourceY,
                    $sourceWidth, $sourceHeight
                );
            }

            // Size.
            if ($sourceWidth !== $destinationWidth
                || $sourceHeight !== $destinationHeight
            ) {
                $hScale = $destinationWidth / $sourceWidth;
                $vScale = $destinationHeight / $sourceHeight;
                $image = $image->resize($hScale, ['vscale' => $vScale]);
            }

            // Mirror.
            switch ($args['mirror']['feature']) {
                case 'mirror':
                case 'horizontal':
                    $image = $image->flip('horizontal');
                    break;
                case 'vertical':
                    $image = $image->flip('vertical');
                    break;
                case 'both':
                    $image = $image->flip('horizontal');
                    $image = $image->flip('vertical');
                    break;
                case 'default':
                    break;
                default:
                    $this->_destroyIfFetched($source);
                    return null;
            }

            // Rotation.
            switch ($args['rotation']['feature']) {
                case 'noRotation':
                    break;
                case 'rotationBy90s':
                    $image = $image->rot(
                        'd' . (int) $args['rotation']['degrees']
                    );
                    break;
                case 'rotationArbitrary':
                    $image = $image->rotate(
                        (float) $args['rotation']['degrees'],
                        ['background' => [0, 0, 0]]
                    );
                    break;
                default:
                    $this->_destroyIfFetched($source);
                    return null;
            }

            // Quality.
            switch ($args['quality']['feature']) {
                case 'default':
                case 'color':
                    break;
                case 'gray':
                    $image = $image->colourspace('grey16');
                    break;
                case 'bitonal':
                    $image = $image->colourspace('b-w');
                    break;
                default:
                    $this->_destroyIfFetched($source);
                    return null;
            }

            // Save.
            $saveOptions = $this->getSaveOptions($args);
            $image->writeToFile($destination, $saveOptions);
        } catch (\Throwable $e) {
            $this->getLogger()->err(
                'PhpVips failed to process "{file}": {message}', // @translate
                ['file' => $source, 'message' => $e->getMessage()]
            );
            $this->_destroyIfFetched($source);
            return null;
        }

        $this->_destroyIfFetched($source);
        return file_exists($destination) ? $destination : null;
    }

    protected function getSaveOptions(array $args): array
    {
        $destOptions = $args['destination']['options'] ?? null;
        if ($destOptions === 'image/tiff') {
            return [
                'compression' => 'jpeg',
                'Q' => 88,
                'tile' => true,
                'tile_width' => 256,
                'tile_height' => 256,
                'pyramid' => true,
                'background' => [0, 0, 0],
            ];
        }

        switch ($args['format']['feature']) {
            case 'image/jpeg':
                return ['Q' => 85, 'strip' => true];
            case 'image/png':
                return ['compression' => 6, 'strip' => true];
            case 'image/webp':
                return ['Q' => 85, 'strip' => true];
            case 'image/tiff':
                return [
                    'compression' => 'jpeg',
                    'Q' => 85,
                    'strip' => true,
                ];
            default:
                return [];
        }
    }
}
