<?php

/*
 * Copyright 2015-2017 Daniel Berthereau
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

use ImageServer\AbstractImageServer;
use Omeka\File\Store\StoreInterface;
use Omeka\File\TempFileFactory;

/**
 * Helper to create an image from another one with IIIF arguments.
 *
 * @package ImageServer
 */
class Auto extends AbstractImageServer
{
    protected $_gdMediaTypes = [];
    protected $_imagickMediaTypes = [];

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * @var array
     */
    protected $commandLineArgs;

    /**
     * Select the thumbnailer according to options.
     *
     * Note: Check for the imagick extension at creation.
     *
     * @throws \Exception
     */
    public function __construct(
        TempFileFactory $tempFileFactory,
        $store,
        array $commandLineArgs
    ) {
        // For simplicity, the check is prepared here, without load of classes.

        // If available, use GD when source and destination formats are managed.
        if (extension_loaded('gd')) {
            $this->_gdMediaTypes = [
                'image/jpeg' => true,
                'image/png' => true,
                'image/tiff' => false,
                'image/gif' => true,
                'application/pdf' => false,
                'image/jp2' => false,
                'image/webp' => true,
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
    }

    /**
     * Transform an image into another image according to params.
     *
     * @internal The args are currently already checked in the controller.
     *
     * @param array $args List of arguments for the transformation.
     * @return string|null The filepath to the temp image if success.
     */
    public function transform(array $args = [])
    {
        // GD seems to be 15% speeder, so it is used first if available.
        if (!empty($this->_gdMediaTypes[$args['source']['media_type']])
            && !empty($this->_gdMediaTypes[$args['format']['feature']])
            // The arbitrary rotation is not managed currently.
            && $args['rotation']['feature'] != 'rotationArbitrary'
        ) {
            $processor = new GD($this->tempFileFactory, $this->store);
            return $processor->transform($args);
        }

        // Else use the extension Imagick, that manages more formats.
        if (!empty($this->_imagickMediaTypes[$args['source']['media_type']])
            && !empty($this->_imagickMediaTypes[$args['format']['feature']])
        ) {
            $processor = new Imagick($this->tempFileFactory, $this->store);
            return $processor->transform($args);
        }

        // Else use the command line convert, if available.
        $processor = new ImageMagick($this->tempFileFactory, $this->store, $this->commandLineArgs);
        return $processor->transform($args);
    }
}
