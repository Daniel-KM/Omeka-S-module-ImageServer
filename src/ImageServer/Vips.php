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
class Vips extends AbstractImager
{
    const VIPS_COMMAND = 'vips';

    /**
     * List of managed IIIF media types.
     *
     * Vips can read jpeg2000 (via ImageMagick), but not write, so it should
     * used with imagemagick. Else use tiff.
     *
     * @var array
     */
    protected $supportedFormats = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/tiff' => 'tif',
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
     * Path to "vips" command.
     *
     * @var string
     */
    protected $vipsPath;

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
        $this->vipsPath = $commandLineArgs['vipsPath'];
        if (empty($this->vipsPath)) {
            $this->supportedFormats = [];
            return;
        }

        // The version lists the common formats simpler than "-list format", but
        // it is not complete.
        // Available only in version 8.8.
        // $command = sprintf($this->vipsPath . ' -l foreign | grep save', $this->vipsPath);
        $command = sprintf($this->vipsPath . ' -l | grep -i save', $this->vipsPath);
        $result = $this->cli->execute($command);
        $matches = [];
        if ($result && preg_match_all('~^.*\(\.(?<extensions>.[.a-z, ]+)\).*$~m', $result, $matches, PREG_SET_ORDER, 0)) {
            $extensions = [];
            foreach ($matches as $match) {
                $ext = array_map(function ($v) {
                    return trim($v, ',.');
                }, explode(' ', strtolower($match['extensions'])));
                $extensions = array_merge($extensions, $ext);
            }
            $this->supportedFormats = array_intersect($this->supportedFormats, $extensions);
            // Include formats managed by ImageMagick if present.
            if (strpos($result, 'ImageMagick')) {
                $im = new ImageMagick($this->tempFileFactory, $this->store, $commandLineArgs);
                $this->supportedFormats += $im->getSupportedFormats();
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
        static $version;
        static $isOldVersion;

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
            list($args['source']['width'], $args['source']['height']) = getimagesize($image);
        }

        // Region + Size.
        $extraction = $this->_prepareExtraction();
        if (!$extraction) {
            $this->_destroyIfFetched($image);
            return null;
        }

        if (is_null($isOldVersion)) {
            $version = (string) $this->cli->execute($this->vipsPath . ' --version');
            $isOldVersion = version_compare($version, 'vips-8.10', '<');
        }

        list(
            $sourceX,
            $sourceY,
            $sourceWidth,
            $sourceHeight,
            $destinationWidth,
            $destinationHeight) = $extraction;

        // The command line vips is not pipable, so an intermediate file is
        // required when there are more than one operation.
        // @link https://libvips.github.io/libvips/API/current/using-cli.html
        // @todo Use php-vips (as a separate imager, because it requires more install).
        // @todo Use vips thumbnail (or vipsthumbnail) when there is only region.

        $chain = [];
        if ($sourceWidth !== $args['source']['width']
            || $sourceHeight !== $args['source']['height']
        ) {
            $chain[] = sprintf(
                '%s extract_area _input_ _output_ %d %d %d %d',
                $this->vipsPath,
                $sourceX,
                $sourceY,
                $sourceWidth,
                $sourceHeight
            );
        }

        if ($sourceWidth !== $destinationWidth
            || $sourceHeight !== $destinationHeight
        ) {
            if ($isOldVersion) {
                $chain[] = sprintf(
                    '%sthumbnail --size=%dx%d --format=_output_ _input_',
                    $this->vipsPath,
                    $destinationWidth,
                    $destinationHeight
                );
            } else {
                // Force because destination width and height are already checked.
                $chain[] = sprintf(
                    '%s thumbnail _input_ _output_ %d --height %d --size force --no-rotate --intent absolute',
                    $this->vipsPath,
                    $destinationWidth,
                    $destinationHeight
                );
            }
        }

        // $chain[] = $this->vipsPath . ' flatten _input_ _output_ --background "0 0 0"';

        // Mirror.
        switch ($args['mirror']['feature']) {
            case 'mirror':
            case 'horizontal':
                $chain[] = $this->vipsPath . ' flip _input_ _output_ horizontal';
                break;

            case 'vertical':
                $chain[] = $this->vipsPath . ' flip _input_ _output_ vertical';
                break;

            case 'both':
                $chain[] = $this->vipsPath . ' flip _input_ _output_ horizontal';
                $chain[] = $this->vipsPath . ' flip _input_ _output_ vertical';
                break;

            case 'default':
                // Nothing to do.
                break;

            default:
                $this->_destroyIfFetched($image);
                return null;
        }

        // Rotation.
        // Can be done in the options of the output too.
        switch ($args['rotation']['feature']) {
            case 'noRotation':
                break;

            case 'rotationBy90s':
                $chain[] = sprintf('%s rot _input_ _output_ d%d', $this->vipsPath, $args['rotation']['degrees']);
                break;

            case 'rotationArbitrary':
                if (version_compare($version, 'vips-8.7', '<')) {
                    $chain[] = sprintf(
                        '%s similarity _input_ _output_ --angle %s',
                        $this->vipsPath,
                        $args['rotation']['degrees']
                    );
                } else {
                    $chain[] = sprintf(
                        '%s rotate _input_ _output_ %s --background "0 0 0"',
                        $this->vipsPath,
                        $args['rotation']['degrees']
                    );
                }
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
                if ($isOldVersion && $this->args['format']['feature'] === 'image/png') {
                    // FIXME Remove this feature from the iiif services.
                } else {
                    $chain[] = $this->vipsPath . ' colourspace _input_ _output_ grey16';
                }
                break;

            case 'bitonal':
                // FIXME vips does not support bitonal/monochrome: b-w is grey on 8 bits. Use convert at last.
                $chain[] = $this->vipsPath . ' colourspace _input_ _output_ b-w';
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

        $destParams = '';
        if (!empty($args['destination']['options'])) {
            if ($args['destination']['options'] === 'image/jp2') {
                // Vips does not manage jp2.
            } elseif ($args['destination']['options'] === 'image/tiff') {
                // @link https://libvips.github.io/libvips/API/current/VipsForeignSave.html#vips-tiffsave
                // @link https://cantaloupe-project.github.io/manual/4.0/images.html
                // @link https://iipimage.sourceforge.io/documentation/images/#TIFF
                // Option "depth=onepixel" is not supported on old vips.
                $destParams = '[compression=jpeg,Q=88,tile,tile-width=256,tile-height=256,pyramid,background=0 0 0]';
            }
        }
        // Fix the icc profile for old versions to bypass a source file with an
        // unmanaged profile.
        // @link https://phabricator.wikimedia.org/T219569
        elseif ($isOldVersion && $this->args['format']['feature'] === 'image/png') {
            $destParams = '[profile=' . dirname(__DIR__, 2) . '/asset/icc/sRGBz.icc' . ']';
        }

        $intermediates = [];

        if (!count($chain)) {
            $command = sprintf(
                '%s copy %s %s',
                $this->vipsPath,
                escapeshellarg($image . '[0]'),
                escapeshellarg($destination . $destParams)
            );
        } elseif (count($chain) === 1) {
            $replace = [
                '_input_' => escapeshellarg($image . '[0]'),
                '_output_' => escapeshellarg($destination . $destParams),
            ];
            $command = str_replace(array_keys($replace), array_values($replace), reset($chain));
        } else {
            $last = count($chain) - 1;
            foreach ($chain as $index => &$part) {
                if ($index === 0) {
                    $replace['_input_'] = escapeshellarg($image . '[0]');
                    $intermediate = $destination . '.' . ($index + 1) . '.vips';
                    $intermediates[] = $intermediate;
                    $replace['_output_'] = escapeshellarg($intermediate);
                    $removePrevPart = '';
                } elseif ($index !== $last) {
                    $current = "$destination.$index.vips";
                    $replace['_input_'] = escapeshellarg($current);
                    $intermediate = $destination . '.' . ($index + 1) . '.vips';
                    $intermediates[] = $intermediate;
                    $replace['_output_'] = escapeshellarg($intermediate);
                    $removePrevPart = ' && rm ' . escapeshellarg($current);
                } else {
                    $current = "$destination.$index.vips";
                    $replace['_input_'] = escapeshellarg($current);
                    $replace['_output_'] = escapeshellarg($destination . $destParams);
                    $removePrevPart = ' && rm ' . escapeshellarg($current);
                }
                $part = str_replace(array_keys($replace), array_values($replace), $part)
                    . $removePrevPart;
            }
            $command = implode(' && ', $chain);
        }

        $result = $this->cli->execute($command);

        // All intermediates are removed in case of an error.
        foreach ($intermediates as $intermediate) {
            @unlink($intermediate);
        }

        $this->_destroyIfFetched($image);

        return $result !== false ? $destination : null;
    }
}
