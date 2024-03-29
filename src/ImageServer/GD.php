<?php declare(strict_types=1);

/*
 * Copyright 2015-2024 Daniel Berthereau
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

/**
 * Helper to create an image from another one with IIIF arguments.
 *
 * @package ImageServer
 */
class GD extends AbstractImager
{
    /**
     * List of managed IIIF media types.
     *
     * @var array
     */
    protected $supportedFormats = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
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
        if (!extension_loaded('gd')) {
            throw new \Exception('The transformation of images via GD requires the PHP extension "gd".');
        }

        $gdInfo = gd_info();
        if (empty($gdInfo['GIF Read Support']) || empty($gdInfo['GIF Create Support'])) {
            unset($this->supportedFormats['image/gif']);
        }
        if (empty($gdInfo['WebP Support'])) {
            unset($this->supportedFormats['image/webp']);
        }

        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;
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

        $sourceGD = $this->_loadImageResource($args['source']['filepath']);
        if (empty($sourceGD)) {
            return null;
        }

        // Get width and height if missing.
        if (empty($args['source']['width']) || empty($args['source']['height'])) {
            $args['source']['width'] = imagesx($sourceGD);
            $args['source']['height'] = imagesy($sourceGD);
        }

        // Region + Size.
        $extraction = $this->_prepareExtraction();
        if (!$extraction) {
            imagedestroy($sourceGD);
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

        $destinationGD = imagecreatetruecolor($destinationWidth, $destinationHeight);
        // The background is normally useless, but it's costless.
        $black = imagecolorallocate($destinationGD, 0, 0, 0);
        imagefill($destinationGD, 0, 0, $black);
        $result = imagecopyresampled($destinationGD, $sourceGD, 0, 0, $sourceX, $sourceY, $destinationWidth, $destinationHeight, $sourceWidth, $sourceHeight);

        if ($result === false) {
            imagedestroy($sourceGD);
            imagedestroy($destinationGD);
            return null;
        }

        // Mirror.
        switch ($args['mirror']['feature']) {
            case 'mirror':
            case 'horizontal':
                $result = imageflip($destinationGD, IMG_FLIP_HORIZONTAL);
                if ($result === false) {
                    imagedestroy($sourceGD);
                    imagedestroy($destinationGD);
                    return null;
                }
                break;

            case 'vertical':
                $result = imageflip($destinationGD, IMG_FLIP_VERTICAL);
                if ($result === false) {
                    imagedestroy($sourceGD);
                    imagedestroy($destinationGD);
                    return null;
                }
                break;

            case 'both':
                $result = imageflip($destinationGD, IMG_FLIP_BOTH);
                if ($result === false) {
                    imagedestroy($sourceGD);
                    imagedestroy($destinationGD);
                    return null;
                }
                break;

            case 'default':
                // Nothing to do.
                break;

            default:
                imagedestroy($sourceGD);
                imagedestroy($destinationGD);
                return null;
        }

        // Rotation.
        switch ($args['rotation']['feature']) {
            case 'noRotation':
                break;

            case 'rotationBy90s':
                switch ($args['rotation']['degrees']) {
                    case 90:
                    case 270:
                        // GD uses counterclockwise rotation.
                        $degrees = $args['rotation']['degrees'] == 90 ? 270 : 90;
                        // Continues below.
                        // no break
                    case 180:
                        $degrees = isset($degrees) ? $degrees : 180;

                        // imagerotate() returns a resource, not a boolean.
                        $destinationGDrotated = imagerotate($destinationGD, $degrees, 0);
                        imagedestroy($destinationGD);
                        if ($destinationGDrotated === false) {
                            imagedestroy($sourceGD);
                            return null;
                        }
                        $destinationGD = &$destinationGDrotated;
                        break;
                }
                break;

            case 'rotationArbitrary':
                // GD uses counterclockwise rotation.
                $degrees = abs(360 - $args['rotation']['degrees']);
                // Keep the transparency if possible.
                $transparency = imagecolorallocatealpha($destinationGD, 0, 0, 0, 127);
                imagefill($destinationGD, 0, 0, $transparency);
                $destinationGDrotated = imagerotate($destinationGD, $degrees, $transparency);
                imagedestroy($destinationGD);
                if ($destinationGDrotated === false) {
                    imagedestroy($sourceGD);
                    return null;
                }
                imagealphablending($destinationGDrotated, true);
                imagesavealpha($destinationGDrotated, true);
                $destinationGD = &$destinationGDrotated;
                break;

            default:
                imagedestroy($sourceGD);
                imagedestroy($destinationGD);
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
                $result = imagefilter($destinationGD, IMG_FILTER_GRAYSCALE);
                if ($result === false) {
                    imagedestroy($sourceGD);
                    imagedestroy($destinationGD);
                    return null;
                }
                break;

            case 'bitonal':
                $result = imagefilter($destinationGD, IMG_FILTER_GRAYSCALE);
                $result = imagefilter($destinationGD, IMG_FILTER_CONTRAST, -65535);
                if ($result === false) {
                    imagedestroy($sourceGD);
                    imagedestroy($destinationGD);
                    return null;
                }
                break;

            default:
                imagedestroy($sourceGD);
                imagedestroy($destinationGD);
                return null;
        }

        // Save resulted resource into the specified format.
        // TODO Use a true name to allow cache, or is it managed somewhere else?
        $destination = $this->prepareDestinationPath();
        if (!$destination) {
            return null;
        }

        switch ($args['format']['feature']) {
            case 'image/jpeg':
                $result = imagejpeg($destinationGD, $destination);
                break;
            case 'image/png':
                $result = imagepng($destinationGD, $destination);
                break;
            case 'image/gif':
                $result = imagegif($destinationGD, $destination);
                break;
            case 'image/webp':
                $result = imagewebp($destinationGD, $destination);
                break;
        }

        imagedestroy($sourceGD);
        imagedestroy($destinationGD);

        return $result ? $destination : null;
    }

    /**
     * Load an image from anywhere.
     *
     * @param string $source Path of the managed image file
     * @return GD|false image ressource
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
                    $image = imagecreatefromstring(file_get_contents($source));
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
                    $image = imagecreatefromstring(file_get_contents($tempPath));
                    unlink($tempPath);
                    break;
            }
        } catch (\Exception $e) {
            $this->getLogger()->err(
                'GD failed to open the file \"{file}\". Details:\n{message}', // @translate
                ['file' => $source, 'message' => $e->getMessage()]
            );
            return false;
        }

        return $image;
    }
}
