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

/**
 * Helper to create an image from another one with IIIF arguments.
 *
 * @package ImageServer
 */
class Auto extends AbstractImager
{
    protected $_gdMediaTypes = [];
    protected $_imagickMediaTypes = [];

    /**
     * @var array
     */
    protected $commandLineArgs;

    /**
     * @var array
     */
    protected $imagers = [];

    /**
     * Select the thumbnailer according to options.
     *
     * Note: Check for the imagick extension at creation.
     *
     * @throws \Exception
     */
    public function __construct(
        TempFileFactory $tempFileFactory,
        StoreInterface $store,
        array $commandLineArgs
    ) {
        // For simplicity, the check is prepared here, without load of classes.

        // If available, use GD when source and destination formats are managed.
        if (extension_loaded('gd')) {
            $this->_gdMediaTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/tiff' => false,
                'image/gif' => 'gif',
                'application/pdf' => false,
                'image/jp2' => false,
                'image/webp' => 'webp',
            ];
            $gdInfo = gd_info();
            if (empty($gdInfo['GIF Read Support']) || empty($gdInfo['GIF Create Support'])) {
                $this->_gdMediaTypes['image/gif'] = false;
            }
            if (empty($gdInfo['WebP Support'])) {
                $this->_gdMediaTypes['image/webp'] = false;
            }
        }

        if (extension_loaded('imagick')) {
            $iiifMediaTypes = [
                'image/jpeg' => 'JPG',
                'image/png' => 'PNG',
                'image/tiff' => 'TIFF',
                'image/gif' => 'GIF',
                'application/pdf' => 'PDF',
                'image/jp2' => 'JP2',
                'image/webp' => 'WEBP',
            ];
            $this->_imagickMediaTypes = array_intersect($iiifMediaTypes, \Imagick::queryFormats());
        }

        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;
        $this->commandLineArgs = $commandLineArgs;

        $imager = new Vips($this->tempFileFactory, $this->store, $this->commandLineArgs);
        $supporteds = $imager->getSupportedFormats();
        if (count($supporteds)) {
            $this->imagers[] = 'Vips';
            $this->supportedFormats = $supporteds;
        }
        if (extension_loaded('gd')) {
            $imager = new GD($this->tempFileFactory, $this->store);
            $supporteds = $imager->getSupportedFormats();
            if (count($supporteds)) {
                $this->imagers[] = 'GD';
                $this->supportedFormats = array_merge($this->supportedFormats, $supporteds);
            }
        }
        if (extension_loaded('imagick')) {
            $imager = new Imagick($this->tempFileFactory, $this->store);
            $supporteds = $imager->getSupportedFormats();
            if (count($supporteds)) {
                $this->imagers[] = 'Imagick';
                $this->supportedFormats = array_merge($this->supportedFormats, $supporteds);
            }
        }
        $imager = new ImageMagick($this->tempFileFactory, $this->store, $this->commandLineArgs);
        $supporteds = $imager->getSupportedFormats();
        if (count($supporteds)) {
            $this->imagers[] = 'ImageMagick';
            $this->supportedFormats = array_merge($this->supportedFormats, $supporteds);
        }
    }

    /**
     * Transform an image into another image according to params.
     *
     * Note: The args are currently already checked in the controller.
     *
     * @param array $args List of arguments for the transformation.
     * @return string|null The filepath to the temp image if success.
     */
    public function transform(array $args): ?string
    {
        if (!count($args)) {
            return null;
        }

        $imager = $this->getImager($args);
        if (empty($imager)) {
            return null;
        }

        return $imager->transform($args);
    }

    public function getImager(array $args)
    {
        // Vips is the quickest, so check it first.
        if (in_array('Vips', $this->imagers)) {
            $imager = new Vips($this->tempFileFactory, $this->store, $this->commandLineArgs);
            if ($imager->checkMediaType($args['source']['media_type'])
                && $imager->checkMediaType($args['format']['feature'])
            ) {
                return $imager
                    ->setLogger($this->getLogger());
            }
        }

        // GD seems to be 15% speeder, so it is used first if available.
        if (in_array('GD', $this->imagers)
            && !empty($this->_gdMediaTypes[$args['source']['media_type']])
            && !empty($this->_gdMediaTypes[$args['format']['feature']])
            // The arbitrary rotation is not managed currently.
            && $args['rotation']['feature'] != 'rotationArbitrary'
        ) {
            $imager = new GD($this->tempFileFactory, $this->store);
            return $imager
                ->setLogger($this->getLogger());
        }

        // Else use the extension Imagick, that manages more formats.
        if (in_array('Imagick', $this->imagers)
            && !empty($this->_imagickMediaTypes[$args['source']['media_type']])
            && !empty($this->_imagickMediaTypes[$args['format']['feature']])
        ) {
            $imager = new Imagick($this->tempFileFactory, $this->store);
            return $imager
                ->setLogger($this->getLogger());
        }

        // Else use the command line ImageMagick.
        if (in_array('ImageMagick', $this->imagers)) {
            $imager = new ImageMagick($this->tempFileFactory, $this->store, $this->commandLineArgs);
            if ($imager->checkMediaType($args['source']['media_type'])
                && $imager->checkMediaType($args['format']['feature'])
            ) {
                return $imager
                    ->setLogger($this->getLogger());
            }
        }

        return null;
    }
}
