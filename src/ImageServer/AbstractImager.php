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

use Laminas\Log\LoggerAwareInterface;
use Laminas\Log\LoggerAwareTrait;
use Omeka\File\Store\StoreInterface;
use Omeka\File\TempFileFactory;
use Omeka\Stdlib\Message;

/**
 * Abstract  to manage strategies used to create an image.
 *
 * @package ImageServer
 */
abstract class AbstractImager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * List of managed IIIF media types and lowercase extensions of the server.
     *
     * @var array
     */
    protected $supportedFormats = [];

    /**
     * @var array
     */
    protected $args = [];

    /**
     * Get the list of supported formats.
     *
     * @param string $mediaType
     * @return bool
     */
    public function getSupportedFormats(): array
    {
        return $this->supportedFormats;
    }

    /**
     * Check if a media type is supported.
     *
     * @param string $mediaType
     * @return bool
     */
    public function checkMediaType($mediaType): bool
    {
        return isset($this->supportedFormats[$mediaType]);
    }

    /**
     * Check if an extension is supported.
     *
     * @param string $extension
     * @return bool
     */
    public function checkExtension($extension): bool
    {
        return in_array(strtolower((string) $extension), $this->supportedFormats);
    }

    /**
     * Get extension for a media type.
     *
     * @param string $mediaType
     * @return ?string
     */
    public function getExtensionForMediaType($mediaType): ?string
    {
        return $this->supportedFormats[$mediaType] ?? null;
    }

    /**
     * Get media type for an extension.
     */
    public function getMediaTypeFromExtension(?string $extension): ?string
    {
        $result = array_search(strtolower((string) $extension), $this->supportedFormats);
        return $result === false ? null : $result;
    }

    /**
     * Transform an image into another image according to params.
     *
     * @internal The args are currently already checked in the controller.
     *
     * @param array $args List of arguments for the transformation.
     * @return string|null The filepath to the temp image if success.
     */
    abstract public function transform(array $args): ?string;

    /**
     * Prepare the extraction from the source and the requested region and size.
     *
     * @return array|null Arguments for the transformation, else null.
     */
    protected function _prepareExtraction(): ?array
    {
        $args = &$this->args;

        // Region.
        switch ($args['region']['feature']) {
            case 'full':
                $sourceX = 0;
                $sourceY = 0;
                $sourceWidth = $args['source']['width'];
                $sourceHeight = $args['source']['height'];
                break;

            case 'regionByPx':
                if ($args['region']['x'] >= $args['source']['width']) {
                    return null;
                }
                if ($args['region']['y'] >= $args['source']['height']) {
                    return null;
                }
                $sourceX = $args['region']['x'];
                $sourceY = $args['region']['y'];
                $sourceWidth = ($sourceX + $args['region']['width']) <= $args['source']['width']
                    ? $args['region']['width']
                    : $args['source']['width'] - $sourceX;
                $sourceHeight = ($sourceY + $args['region']['height']) <= $args['source']['height']
                    ? $args['region']['height']
                    : $args['source']['height'] - $sourceY;
                break;

            case 'regionByPct':
                $sourceX = $args['source']['width'] * $args['region']['x'] / 100;
                $sourceY = $args['source']['height'] * $args['region']['y'] / 100;
                $sourceWidth = ($args['region']['x'] + $args['region']['width']) <= 100
                    ? $args['source']['width'] * $args['region']['width'] / 100
                    : $args['source']['width'] - $sourceX;
                $sourceHeight = ($args['region']['y'] + $args['region']['height']) <= 100
                    ? $args['source']['height'] * $args['region']['height'] / 100
                    : $args['source']['height'] - $sourceY;
                break;

            default:
                return null;
        }

        // Final generic check for region of the source.
        if ($sourceX < 0 || $sourceX >= $args['source']['width']
            || $sourceY < 0 || $sourceY >= $args['source']['height']
            || $sourceWidth <= 0 || $sourceWidth > $args['source']['width']
            || $sourceHeight <= 0 || $sourceHeight > $args['source']['height']
        ) {
            return null;
        }

        // Size.
        // The size is checked against the region, not the source.
        switch ($args['size']['feature']) {
            case 'full':
            case 'max':
                $destinationWidth = $sourceWidth;
                $destinationHeight = $sourceHeight;
                break;

            case 'sizeByPct':
                $destinationWidth = $sourceWidth * $args['size']['percentage'] / 100;
                $destinationHeight = $sourceHeight * $args['size']['percentage'] / 100;
                break;

            case 'sizeByWhListed':
            case 'sizeByForcedWh':
                $destinationWidth = $args['size']['width'];
                $destinationHeight = $args['size']['height'];
                break;

            case 'sizeByW':
                $destinationWidth = $args['size']['width'];
                $destinationHeight = $destinationWidth * $sourceHeight / $sourceWidth;
                break;

            case 'sizeByH':
                $destinationHeight = $args['size']['height'];
                $destinationWidth = $destinationHeight * $sourceWidth / $sourceHeight;
                break;

            case 'sizeByWh':
                // Check sizes before testing.
                if ($args['size']['width'] > $sourceWidth) {
                    $args['size']['width'] = $sourceWidth;
                }
                if ($args['size']['height'] > $sourceHeight) {
                    $args['size']['height'] = $sourceHeight;
                }
                // no break.
            case 'sizeByConfinedWh':
                // Check ratio to find best fit.
                $destinationHeight = $args['size']['width'] * $sourceHeight / $sourceWidth;
                if ($destinationHeight > $args['size']['height']) {
                    $destinationWidth = $args['size']['height'] * $sourceWidth / $sourceHeight;
                    $destinationHeight = $args['size']['height'];
                }
                // Ratio of height is better, so keep it.
                else {
                    $destinationWidth = $args['size']['width'];
                }
                break;

            default:
                return null;
        }

        // Final generic checks for size.
        // In version 2, size 0 is not allowed, but in version 3, minimum size is 1.
        if (version_compare($args['version'], '3', '<')) {
            if (empty($destinationWidth) || empty($destinationHeight)) {
                return null;
            }
        } elseif ($destinationWidth < 1 || $destinationHeight < 1) {
            return null;
        }

        return [
            (int) $sourceX,
            (int) $sourceY,
            (int) $sourceWidth,
            (int) $sourceHeight,
            (int) $destinationWidth,
            (int) $destinationHeight,
        ];
    }

    /**
     * Load an image from anywhere.
     *
     * @param string $source Path of the managed image file
     * @return false|string
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
                    $image = $source;
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
                    $this->fetched[$tempPath] = true;
                    $image = $tempPath;
                    break;
            }
        } catch (\Exception $e) {
            $message = new Message('Image Server failed to open the file "%s". Details:
%s', $source, $e->getMessage()); // @translate
            $this->getLogger()->err($message);
            return false;
        }

        return $image;
    }

    /**
     * Destroy an image if fetched.
     *
     * @param string $image
     */
    protected function _destroyIfFetched($image): void
    {
        if (isset($this->fetched[$image])) {
            unlink($image);
            unset($this->fetched[$image]);
        }
    }

    protected function prepareDestinationPath(): ?string
    {
        if (empty($this->args['destination']['filepath'])) {
            $extension = $this->supportedFormats[$this->args['format']['feature']];
            $tempFile = $this->tempFileFactory->build();
            $destination = $tempFile->getTempPath() . '.' . $extension;
            $tempFile->delete();
            return $destination;
        }

        $destination = $this->args['destination']['filepath'];
        if (file_exists($destination)) {
            if (!is_writeable($destination)) {
                $message = new Message('Unable to save the file "%s".', $destination); // @translate
                $this->getLogger()->err($message);
                return null;
            }
            @unlink($destination);
            return $destination;
        }

        $dir = dirname($destination);
        if (file_exists($dir)) {
            if (!is_writeable($dir)) {
                $message = new Message('Unable to save the file "%s": directory is not writeable.', $destination); // @translate
                $this->getLogger()->err($message);
                return null;
            }
            return $destination;
        }

        $result = @mkdir($dir, 0775, true);
        return $result
            ? $destination
            : null;
    }
}
