<?php declare(strict_types=1);

/*
 * Copyright 2015-2023 Daniel Berthereau
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
use Omeka\Stdlib\Cli;

/**
 * Helper to create an image from another one with IIIF arguments.
 *
 * @package ImageServer
 */
class ImageMagick extends AbstractImager
{
    /**
     * List of managed IIIF media types.
     *
     * @var array
     */
    protected $supportedFormats = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/tiff' => 'tiff',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'image/jp2' => 'jp2',
        'image/webp' => 'webp',
    ];

    /**
     * @var Cli
     */
    protected $cli;

    /**
     * Path to the ImageMagick "convert" command.
     *
     * @var string
     */
    protected $convertPath;

    /**
     * List of the fetched images in order to remove them after process.
     *
     * @var array
     */
    protected $fetched = [];

    /**
     * Check for the php extension.
     *
     * @param TempFileFactory $tempFileFactory
     * @param StoreInterface $store
     * @param array $commandLineArgs
     * @throws \Exception
     */
    public function __construct(
        TempFileFactory $tempFileFactory,
        StoreInterface $store,
        $commandLineArgs
    ) {
        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;
        $this->cli = $commandLineArgs['cli'];
        $this->convertPath = $commandLineArgs['convertPath'];
        if (empty($this->convertPath)) {
            $this->supportedFormats = [];
            return;
        }

        // The version list the common formats simpler than "-list format", but
        // it is not complete.
        $command = sprintf($this->convertPath . ' -list format 2>/dev/null ; echo ""', $this->convertPath);
        $result = $this->cli->execute($command);
        $matches = [];
        // For simplicity, manage only read and write formats.
        if ($result && preg_match_all('~^\s*(?<format>[^ *]+)\*?\s+(?<module>[^ ]+)\s+(?<mode>rw).*$~m', $result, $matches, PREG_SET_ORDER, 0)) {
            $formats = [];
            foreach ($matches as $match) {
                $key = array_search(strtolower($match['module']), $this->supportedFormats);
                if ($key) {
                    $formats[$key] = $this->supportedFormats[$key];
                }
            }
            $this->supportedFormats = $formats;
            // The IIIF standard requires "jpg" and "tif", not "jpeg" or "tiff".
            if (isset($this->supportedFormats['image/jpeg'])) {
                $this->supportedFormats['image/jpeg'] = 'jpg';
            }
            if (isset($this->supportedFormats['image/tiff'])) {
                $this->supportedFormats['image/tiff'] = 'tif';
            }
        } else {
            $this->supportedFormats = [];
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

        $image = $this->_loadImageResource($args['source']['filepath']);
        if (empty($image)) {
            return null;
        }

        // Get width and height if missing.
        if (empty($args['source']['width']) || empty($args['source']['height'])) {
            [$args['source']['width'], $args['source']['height']] = getimagesize($image);
        }

        // Region + Size.
        $extraction = $this->_prepareExtraction();
        if (!$extraction) {
            $this->_destroyIfFetched($image);
            return null;
        }

        [
            $sourceX,
            $sourceY,
            $sourceWidth,
            $sourceHeight,
            $destinationWidth,
            $destinationHeight] = $extraction;

        $params = [];
        // The background is normally useless, but it's costless.
        $params[] = '-background black';
        $params[] = '+repage';
        $params[] = '-flatten';
        $params[] = '-page ' . escapeshellarg(sprintf('%sx%s+0+0', $sourceWidth, $sourceHeight));
        $params[] = '-crop ' . escapeshellarg(sprintf('%dx%d+%d+%d', $sourceWidth, $sourceHeight, $sourceX, $sourceY));
        $params[] = '-thumbnail ' . escapeshellarg(sprintf('%sx%s', $destinationWidth, $destinationHeight));
        $params[] = '-page ' . escapeshellarg(sprintf('%sx%s+0+0', $destinationWidth, $destinationHeight));

        // Mirror.
        switch ($args['mirror']['feature']) {
            case 'mirror':
            case 'horizontal':
                $params[] = '-flop';
                break;

            case 'vertical':
                $params[] = '-flip';
                break;

            case 'both':
                $params[] = '-flop';
                $params[] = '-flip';
                break;

            case 'default':
                // Nothing to do.
                break;

            default:
                $this->_destroyIfFetched($image);
                return null;
        }

        // Rotation.
        switch ($args['rotation']['feature']) {
            case 'noRotation':
                break;

            case 'rotationBy90s':
            case 'rotationArbitrary':
                $params[] = '-rotate ' . escapeshellarg($args['rotation']['degrees']);
                break;

            default:
                $this->_destroyIfFetched($image);
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
                $params[] = '-colorspace Gray';
                break;

            case 'bitonal':
                $params[] = '-monochrome';
                break;

            default:
                $this->_destroyIfFetched($image);
                return null;
        }

        // Save resulted resource into the specified format.
        $destination = $this->prepareDestinationPath();
        if (!$destination) {
            $this->_destroyIfFetched($image);
            return null;
        }

        $prefixedFormat = $this->supportedFormats[$args['format']['feature']];

        if (!empty($args['destination']['options'])) {
            if ($args['destination']['options'] === 'image/jp2') {
                // @link https://imagemagick.org/script/jp2.php
                // @link https://cantaloupe-project.github.io/manual/4.0/images.html
                // @link https://iipimage.sourceforge.io/documentation/images/#JPEG2000
                // -r 2.5 -n 7 -c "[256,256]" -b "64,64" -p RPCL -SOP -t 256,256 -TP R
                $params[] = '-define jp2:r=2.5 -define jp2:n=7 -define jp2:c="[256,256]" -define jp2:b="64,64" -define jp2:p=RPCL -define jp2:SOP -define jp2:t="256,256" -define jp2:TP=R';
            } elseif ($args['destination']['options'] === 'image/tiff') {
                // @link https://cantaloupe-project.github.io/manual/4.0/images.html
                // @link https://iipimage.sourceforge.io/documentation/images/#TIFF
                // The depth 8 bits is added to get compatible preview.
                // convert s -define tiff:tile-geometry=256x256 -compress jpeg 'ptif:o.tif'
                $params[] = '-define tiff:tile-geometry=256x256 -compress jpeg -depth 8';
                // Set the pyramidal tiff prefix.
                $prefixedFormat = 'ptif';
            }
        }

        $command = sprintf(
            '%s %s %s %s',
            $this->convertPath,
            escapeshellarg($image . '[0]'),
            implode(' ', $params),
            escapeshellarg($prefixedFormat . ':' . $destination)
        );

        $result = $this->cli->execute($command);

        $this->_destroyIfFetched($image);

        return $result !== false ? $destination : null;
    }
}
