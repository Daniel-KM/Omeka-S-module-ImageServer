<?php declare(strict_types=1);

/*
 * Copyright 2015-2023 Daniel Berthereau
 * Copyright 2016-2017 BibLibre
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace ImageServer\ImageServer;

use Omeka\File\Store\StoreInterface;
use Omeka\File\TempFileFactory;
use Omeka\Stdlib\Message;

/**
 * Helper to create an image from another one with IIIF arguments.
 *
 * @package ImageServer
 */
class Imagick extends AbstractImager
{
    /**
     * List of managed IIIF media types.
     *
     * Imagick requires uppercase for check. They are lowercased in construct.
     *
     * @var array
     */
    protected $supportedFormats = [
        'image/jpeg' => 'JPG',
        'image/png' => 'PNG',
        'image/tiff' => 'TIFF',
        'image/gif' => 'GIF',
        'application/pdf' => 'PDF',
        'image/jp2' => 'JP2',
        'image/webp' => 'WEBP',
    ];

    /**
     * Check for the php extension.
     *
     * @param TempFileFactory $tempFileFactory
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function __construct(
        TempFileFactory $tempFileFactory,
        StoreInterface $store
    ) {
        if (!extension_loaded('imagick')) {
            throw new \Exception('The transformation of images via ImageMagick requires the PHP extension "imagick".');
        }

        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;
        $this->supportedFormats = array_map('strtolower', array_intersect($this->supportedFormats, \Imagick::queryFormats()));

        // The IIIF standard requires "tif", not "tiff".
        if (isset($this->supportedFormats['image/tiff'])) {
            $this->supportedFormats['image/tiff'] = 'tif';
        }
    }

    /**
     * Transform an image into another image according to params.
     *
     * @internal The args are currently already checked in the controller.
     *
     * @param array $args List of arguments for the transformation.
     * @return string|null The filepath to the temp image if success.
     */
    public function transform(array $args): ?string
    {
        if (!count($args)) {
            return null;
        }

        $this->args = $args;
        $args = &$this->args;

        if (!$this->checkMediaType($args['source']['media_type'])
            || !$this->checkMediaType($args['format']['feature'])
        ) {
            return null;
        }

        $imagick = $this->_loadImageResource($args['source']['filepath']);
        if (empty($imagick)) {
            return null;
        }

        // Get width and height if missing.
        if (empty($args['source']['width']) || empty($args['source']['height'])) {
            $args['source']['width'] = $imagick->getImageWidth();
            $args['source']['height'] = $imagick->getImageHeight();
        }

        // Region + Size.
        $extraction = $this->_prepareExtraction();
        if (!$extraction) {
            $imagick->clear();
            return null;
        }

        list(
            $sourceX,
            $sourceY,
            $sourceWidth,
            $sourceHeight,
            $destinationWidth,
            $destinationHeight) = $extraction;

        // The background is normally useless, but it's costless.
        $imagick->setBackgroundColor('black');
        $imagick->setImageBackgroundColor('black');
        $imagick->setImagePage($sourceWidth, $sourceHeight, 0, 0);
        $imagick->cropImage($sourceWidth, $sourceHeight, $sourceX, $sourceY);
        $imagick->thumbnailImage($destinationWidth, $destinationHeight);
        $imagick->setImagePage($destinationWidth, $destinationHeight, 0, 0);

        // Mirror.
        switch ($args['mirror']['feature']) {
            case 'mirror':
            case 'horizontal':
                $imagick->flopImage();
                break;

            case 'vertical':
                $imagick->flipImage();
                break;

            case 'both':
                $imagick->flopImage();
                $imagick->flipImage();
                break;

            case 'default':
                // Nothing to do.
                break;

            default:
                $imagick->clear();
                return null;
        }

        // Rotation.
        switch ($args['rotation']['feature']) {
            case 'noRotation':
                break;

            case 'rotationBy90s':
            case 'rotationArbitrary':
                $imagick->rotateimage('black', $args['rotation']['degrees']);
                break;

            default:
                $imagick->clear();
                return null;
        }

        // Quality.
        switch ($args['quality']['feature']) {
            case 'default':
                break;

            case 'color':
                // No change, because only one image is managed.
                break;

            case 'gray':
                $imagick->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
                break;

            case 'bitonal':
                $imagick->thresholdImage(0.77 * $imagick->getQuantum());
                break;

            default:
                $imagick->clear();
                return null;
        }

        // Save resulted resource into the specified format.
        $destination = $this->prepareDestinationPath();
        if (!$destination) {
            return null;
        }

        $imageFormat = $this->supportedFormats[$args['format']['feature']];
        $prefixedFormat = $this->supportedFormats[$args['format']['feature']];

        if (!empty($args['destination']['options'])) {
            if ($args['destination']['options'] === 'image/jp2') {
                // @link https://imagemagick.org/script/jp2.php
                // @link https://cantaloupe-project.github.io/manual/4.0/images.html
                // @link https://iipimage.sourceforge.io/documentation/images/#JPEG2000
                // -r 2.5 -n 7 -c "[256,256]" -b "64,64" -p RPCL -SOP -t 256,256 -TP R
                $imagick->setOption('jp2:r', '2.5');
                $imagick->setOption('jp2:n', '7');
                $imagick->setOption('jp2:c', '[256,256]');
                $imagick->setOption('jp2:b', '64,64');
                $imagick->setOption('jp2:p', 'RPCL');
                $imagick->setOption('jp2:SOP', '');
                $imagick->setOption('jp2:t', '256,256');
                $imagick->setOption('jp2:TP', 'R');
            } elseif ($args['destination']['options'] === 'image/tiff') {
                // @link https://cantaloupe-project.github.io/manual/4.0/images.html
                // @link https://iipimage.sourceforge.io/documentation/images/#TIFF
                // The depth 8 bits is added to get compatible preview.
                // convert s -define tiff:tile-geometry=256x256 -compress jpeg 'ptif:o.tif'
                $imagick->setFormat('ptif');
                $imagick->setOption('tiff:tile-geometry', '256x256');
                $imagick->setImageDepth(8);
                $imageFormat = 'jpeg';
                $prefixedFormat = 'ptif';
            }
        }

        $imagick->setImageFormat($imageFormat);
        $result = $imagick->writeImage($prefixedFormat . ':' . $destination);

        $imagick->clear();

        return $result ? $destination : null;
    }

    /**
     * Load an image from anywhere.
     *
     * @param string $source Path of the managed image file
     * @return Imagick|false
     */
    protected function _loadImageResource($source)
    {
        if (empty($source)) {
            return false;
        }

        try {
            // A check is added if the file is local: the source can be a local file
            // or an external one (Amazon S3…).
            switch (get_class($this->store)) {
                case \Omeka\File\Store\Local::class:
                    if (!is_readable($source)) {
                        return false;
                    }
                    $imagick = new \Imagick($source);
                    break;

                // When the storage is external, the file is fetched before.
                default:
                    $tempFile = $this->tempFileFactory->build();
                    $tempPath = $tempFile->getTempPath();
                    $tempFile->delete();
                    $result = copy($source, $tempPath);
                    if (!$result) {
                        return false;
                    }
                    $imagick = new \Imagick($tempPath);
                    unlink($tempPath);
                    break;
            }
        } catch (\Exception $e) {
            $message = new Message('Imagick failed to open the file \"%s\". Details:\n%s', $source, $e->getMessage()); // @translate
            $this->getLogger()->err($message);
            return false;
        }

        return $imagick;
    }
}
