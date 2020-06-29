<?php

/*
 * Copyright 2015-2020 Daniel Berthereau
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

namespace ImageServer\Controller;

use ImageServer\ImageServer;
use Omeka\Api\Exception\BadRequestException;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Store\StoreInterface;
use Omeka\File\TempFileFactory;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Mvc\Exception\UnsupportedMediaTypeException;
use Zend\I18n\Translator\TranslatorInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

/**
 * The Image controller class.
 *
 * @todo Move all image processing stuff in Image Server.
 *
 * @package ImageServer
 */
class ImageController extends AbstractActionController
{
    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * @var ModuleManager
     */
    protected $moduleManager;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var array
     */
    protected $commandLineArgs;

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $version;

    public function __construct(
        TempFileFactory $tempFileFactory,
        $store,
        ModuleManager $moduleManager,
        TranslatorInterface $translator,
        array $commandLineArgs,
        $basePath
    ) {
        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;
        $this->moduleManager = $moduleManager;
        $this->translator = $translator;
        $this->commandLineArgs = $commandLineArgs;
        $this->basePath = $basePath;
    }

    /**
     * Redirect to the 'info' action, required by the feature "baseUriRedirect".
     *
     * @see self::infoAction()
     */
    public function indexAction()
    {
        $id = $this->params('id');
        $url = $this->url()->fromRoute('imageserver/info', ['id' => $id], ['force_canonical' => true]);
        return $this->getResponse()
            // TODO The iiif image api specification recommands 303, not 302.
            ->setStatusCode(\Zend\Http\Response::STATUS_CODE_303)
            ->getHeaders()->addHeaderLine('Location', $url);
    }

    /**
     * Returns an error 400 to requests that are invalid.
     */
    public function badAction()
    {
        $message = 'The Image server cannot fulfill the request: the arguments are incorrect.'; // @translate
        return $this->viewError(new BadRequestException($message), \Zend\Http\Response::STATUS_CODE_400);
    }

    /**
     * Send "info.json" for the current file.
     *
     * The info is managed by the ImageControler because it indicates
     * capabilities of the Image server for the request of a file.
     */
    public function infoAction()
    {
        $resource = $this->fetchResource('media');
        if (!$resource) {
            return $this->jsonError(new NotFoundException, \Zend\Http\Response::STATUS_CODE_404);
        }

        $this->requestedVersion();

        $iiifInfo = $this->viewHelpers()->get('iiifInfo');
        try {
            $info = $iiifInfo($resource, $this->version);
        } catch (\IiifServer\Iiif\Exception\RuntimeException $e) {
            return $this->jsonError($e, \Zend\Http\Response::STATUS_CODE_400);
        }

        return $this->iiifImageJsonLd($info, $this->version);
    }

    /**
     * Returns sized image for the current file.
     */
    public function fetchAction()
    {
        $media = $this->fetchResource('media');
        if (!$media) {
            return $this->jsonError(new NotFoundException, \Zend\Http\Response::STATUS_CODE_404);
        }

        $response = $this->getResponse();

        // Check if the original file is an image.
        if (strpos($media->mediaType(), 'image/') !== 0) {
            return $this->viewError(new UnsupportedMediaTypeException(
                sprintf('The media "%d" is not an image', $media->id()), // @translate
                \Zend\Http\Response::STATUS_CODE_501
            ));
        }

        $this->requestedVersion();

        // Check, clean and optimize and fill values according to the request.
        $this->_view = new ViewModel;
        $transform = $this->_cleanRequest($media);
        if (empty($transform)) {
            // The message is set in view.
            $response->setStatusCode(400);
            return $this->_view
                ->setTemplate('image-server/image/error');
        }

        $settings = $this->settings();

        // Now, process the requested transformation if needed.
        $imageUrl = '';
        $imagePath = '';

        // A quick check when there is no transformation.
        if ($transform['region']['feature'] == 'full'
                && $transform['size']['feature'] == 'full'
                && $transform['mirror']['feature'] == 'default'
                && $transform['rotation']['feature'] == 'noRotation'
                && $transform['quality']['feature'] == 'default'
                && $transform['format']['feature'] == $media->mediaType()
            ) {
            $imageUrl = $media->originalUrl();
        }

        // A transformation is needed.
        else {
            // Quick check if an Omeka derivative is appropriate.
            $pretiled = $this->_useOmekaDerivative($media, $transform);
            if ($pretiled) {
                // Check if a light transformation is needed.
                if ($transform['size']['feature'] != 'full'
                        || $transform['mirror']['feature'] != 'default'
                        || $transform['rotation']['feature'] != 'noRotation'
                        || $transform['quality']['feature'] != 'default'
                        || $transform['format']['feature'] != $pretiled['media_type']
                    ) {
                    $args = $transform;
                    $args['source']['filepath'] = $pretiled['filepath'];
                    $args['source']['media_type'] = $pretiled['media_type'];
                    $args['source']['width'] = $pretiled['width'];
                    $args['source']['height'] = $pretiled['height'];
                    $args['region']['feature'] = 'full';
                    $args['region']['x'] = 0;
                    $args['region']['y'] = 0;
                    $args['region']['width'] = $pretiled['width'];
                    $args['region']['height'] = $pretiled['height'];
                    $imagePath = $this->_transformImage($args);
                }
                // No transformation.
                else {
                    $imageUrl = $media->thumbnailUrl($pretiled['derivativeType']);
                }
            }

            // Check if another image can be used.
            else {
                // Check if the image is pre-tiled.
                $pretiled = $this->_usePreTiled($media, $transform);
                if ($pretiled) {
                    // Warning: Currently, the tile server does not manage
                    // regions or special size, so it is possible to process the
                    // crop of an overlap in one transformation.

                    // Check if a light transformation is needed (all except
                    // extraction of the region).
                    if (($pretiled['overlap'] && !$pretiled['isSingleCell'])
                            || $transform['mirror']['feature'] != 'default'
                            || $transform['rotation']['feature'] != 'noRotation'
                            || $transform['quality']['feature'] != 'default'
                            || $transform['format']['feature'] != $pretiled['media_type']
                        ) {
                        $args = $transform;
                        $args['source']['filepath'] = $pretiled['filepath'];
                        $args['source']['media_type'] = $pretiled['media_type'];
                        $args['source']['width'] = $pretiled['width'];
                        $args['source']['height'] = $pretiled['height'];
                        // The tile server returns always the true tile, so crop
                        // it when there is an overlap.
                        if ($pretiled['overlap']) {
                            $args['region']['feature'] = 'regionByPx';
                            $args['region']['x'] = $pretiled['isFirstColumn'] ? 0 : $pretiled['overlap'];
                            $args['region']['y'] = $pretiled['isFirstRow'] ? 0 : $pretiled['overlap'];
                            $args['region']['width'] = $pretiled['size'];
                            $args['region']['height'] = $pretiled['size'];
                        }
                        // Normal tile.
                        else {
                            $args['region']['feature'] = 'full';
                            $args['region']['x'] = 0;
                            $args['region']['y'] = 0;
                            $args['region']['width'] = $pretiled['width'];
                            $args['region']['height'] = $pretiled['height'];
                        }
                        $args['size']['feature'] = 'full';
                        $imagePath = $this->_transformImage($args);
                    }
                    // No transformation.
                    else {
                        $imageUrl = $pretiled['fileurl'];
                    }
                }

                // The image needs to be transformed dynamically.
                else {
                    $maxFileSize = $settings->get('imageserver_image_max_size');
                    if (!empty($maxFileSize) && $this->_mediaFileSize($media) > $maxFileSize) {
                        return $this->viewError(new \IiifServer\Iiif\Exception\RuntimeException(
                            'The Image server encountered an unexpected error that prevented it from fulfilling the request: the file is not tiled for dynamic processing.', // @translate
                            \Zend\Http\Response::STATUS_CODE_500
                        ));
                    }
                    $imagePath = $this->_transformImage($transform);
                }
            }
        }

        // Redirect to the url when an existing file is available.
        if ($imageUrl) {
            $response->getHeaders()
                // Header for CORS, required for access of IIIF.
                ->addHeaderLine('access-control-allow-origin', '*')
                // Recommanded by feature "profileLinkHeader".
                ->addHeaderLine('Link', version_compare($this->version, '3', '<')
                    ? '<http://iiif.io/api/image/2/level2.json>;rel="profile"'
                    : '<http://iiif.io/api/image/3/>;rel="profile"'
                )
                ->addHeaderLine('Content-Type', $transform['format']['feature']);

            // Redirect (302/307) to the url of the file.
            // TODO This is a local file (normal server, except iiip server): use 200.
            return $this->redirect()->toUrl($imageUrl);
        }

        //This is a transformed file.
        elseif ($imagePath) {
            $output = file_get_contents($imagePath);
            unlink($imagePath);

            if (empty($output)) {
                return $this->viewError(new \IiifServer\Iiif\Exception\RuntimeException(
                    'The Image server encountered an unexpected error that prevented it from fulfilling the request: the resulting file is not found or empty.', // @translate
                    \Zend\Http\Response::STATUS_CODE_500
                ));
            }

            $response->getHeaders()
                // Header for CORS, required for access of IIIF.
                ->addHeaderLine('access-control-allow-origin', '*')
                // Recommanded by feature "profileLinkHeader".
                ->addHeaderLine('Link', version_compare($this->version, '3', '<')
                    ? '<http://iiif.io/api/image/2/level2.json>;rel="profile"'
                    : '<http://iiif.io/api/image/3/>;rel="profile"'
                )
                ->addHeaderLine('Content-Type', $transform['format']['feature']);

            $response->setContent($output);
            return $response;
        }

        // No result.
        else {
            return $this->viewError(new \IiifServer\Iiif\Exception\RuntimeException(
                'The Image server encountered an unexpected error that prevented it from fulfilling the request: the resulting file is empty or not found.', // @translate
                \Zend\Http\Response::STATUS_CODE_500
            ));
        }
    }

    protected function _mediaFileSize(MediaRepresentation $media)
    {
        $filepath = $this->_mediaFilePath($media);
        return filesize($filepath);
    }

    protected function _mediaFilePath(MediaRepresentation $media, $imageType = 'original')
    {
        if ($imageType == 'original') {
            $storagePath = $this->getStoragePath($imageType, $media->filename());
        } else {
            $storagePath = $this->getStoragePath($imageType, $media->storageId(), 'jpg');
        }
        $filepath = $this->basePath
            . DIRECTORY_SEPARATOR . $storagePath;

        return $filepath;
    }

    /**
     * Check, clean and optimize the request for quicker transformation.
     *
     * @todo Move the maximum of checks in the Image Server.
     *
     * @param MediaRepresentation $media
     * @return array|null Array of cleaned requested image, else null.
     */
    protected function _cleanRequest(MediaRepresentation $media)
    {
        $transform = [];

        $transform['source']['filepath'] = $this->_getImagePath($media, 'original');
        $transform['source']['media_type'] = $media->mediaType();

        $imageSize = $this->imageSize($media, 'original');
        list($sourceWidth, $sourceHeight) = $imageSize ? array_values($imageSize) : [null, null];
        $transform['source']['width'] = $sourceWidth;
        $transform['source']['height'] = $sourceHeight;

        // The regex in the route implies that all requests are valid (no 501),
        // but may be bad formatted (400).

        $region = $this->params('region');
        $size = $this->params('size');
        $rotation = $this->params('rotation');
        $quality = $this->params('quality');
        $format = $this->params('format');

        // Determine the region.

        // Full image.
        // Manage the case where the source and requested images are square too.
        // TODO Square is not supported by 2.0, only by 2.1, but the iiif validator bypasses it.
        if ($region == 'full' || ($region === 'square' && $sourceWidth === $sourceHeight)) {
            $transform['region']['feature'] = 'full';
            // Next values may be needed for next parameters.
            $transform['region']['x'] = 0;
            $transform['region']['y'] = 0;
            $transform['region']['width'] = $sourceWidth;
            $transform['region']['height'] = $sourceHeight;
        }

        // Square image.
        elseif ($region == 'square') {
            $transform['region']['feature'] = 'regionByPx';
            if ($sourceWidth > $sourceHeight) {
                $transform['region']['x'] = (int) (($sourceWidth - $sourceHeight) / 2);
                $transform['region']['y'] = 0;
                $transform['region']['width'] = $sourceHeight;
                $transform['region']['height'] = $sourceHeight;
            } else {
                $transform['region']['x'] = 0;
                $transform['region']['y'] = (int) (($sourceHeight - $sourceWidth) / 2);
                $transform['region']['width'] = $sourceWidth;
                $transform['region']['height'] = $sourceWidth;
            }
        }

        // "pct:x,y,w,h": regionByPct
        elseif (strpos($region, 'pct:') === 0) {
            $regionValues = explode(',', substr($region, 4));
            if (count($regionValues) != 4) {
                $this->_view->setVariable('message', sprintf($this->translate('The Image server cannot fulfill the request: the region "%s" is incorrect.'), $region));
                return null;
            }
            $regionValues = array_map('floatval', $regionValues);
            // A quick check to avoid a possible transformation.
            if ($regionValues[0] == 0
                    && $regionValues[1] == 0
                    && $regionValues[2] == 100
                    && $regionValues[3] == 100
                ) {
                $transform['region']['feature'] = 'full';
                // Next values may be needed for next parameters.
                $transform['region']['x'] = 0;
                $transform['region']['y'] = 0;
                $transform['region']['width'] = $sourceWidth;
                $transform['region']['height'] = $sourceHeight;
            }
            // Normal region.
            else {
                $transform['region']['feature'] = 'regionByPct';
                $transform['region']['x'] = $regionValues[0];
                $transform['region']['y'] = $regionValues[1];
                $transform['region']['width'] = $regionValues[2];
                $transform['region']['height'] = $regionValues[3];
            }
        }

        // "x,y,w,h": regionByPx.
        else {
            $regionValues = explode(',', $region);
            if (count($regionValues) != 4) {
                $this->_view->setVariable('message', sprintf($this->translate('The Image server cannot fulfill the request: the region "%s" is incorrect.'), $region));
                return null;
            }
            $regionValues = array_map('intval', $regionValues);
            // A quick check to avoid a possible transformation.
            if ($regionValues[0] == 0
                    && $regionValues[1] == 0
                    && $regionValues[2] == $sourceWidth
                    && $regionValues[3] == $sourceHeight
                ) {
                $transform['region']['feature'] = 'full';
                // Next values may be needed for next parameters.
                $transform['region']['x'] = 0;
                $transform['region']['y'] = 0;
                $transform['region']['width'] = $sourceWidth;
                $transform['region']['height'] = $sourceHeight;
            }
            // Normal region.
            else {
                $transform['region']['feature'] = 'regionByPx';
                $transform['region']['x'] = $regionValues[0];
                $transform['region']['y'] = $regionValues[1];
                $transform['region']['width'] = $regionValues[2];
                $transform['region']['height'] = $regionValues[3];
            }
        }

        // Determine the size.

        // Full image.
        if ($size == 'full') {
            $transform['size']['feature'] = 'full';
        }

        // "pct:x": sizeByPct
        elseif (strpos($size, 'pct:') === 0) {
            $sizePercentage = floatval(substr($size, 4));
            if (empty($sizePercentage) || $sizePercentage > 100) {
                $this->_view->setVariable('message', sprintf($this->translate('The Image server cannot fulfill the request: the size "%s" is incorrect.'), $size));
                return null;
            }
            // A quick check to avoid a possible transformation.
            if ($sizePercentage == 100) {
                $transform['size']['feature'] = 'full';
            }
            // Normal size.
            else {
                $transform['size']['feature'] = 'sizeByPct';
                $transform['size']['percentage'] = $sizePercentage;
            }
        }

        // "!w,h": sizeByWh
        elseif (strpos($size, '!') === 0) {
            $pos = strpos($size, ',');
            $destinationWidth = (int) substr($size, 1, $pos);
            $destinationHeight = (int) substr($size, $pos + 1);
            if (empty($destinationWidth) || empty($destinationHeight)) {
                $this->_view->setVariable('message', sprintf($this->translate('The Image server cannot fulfill the request: the size "%s" is incorrect.'), $size));
                return null;
            }
            // A quick check to avoid a possible transformation.
            if ($destinationWidth == $transform['region']['width']
                    && $destinationHeight == $transform['region']['width']
                ) {
                $transform['size']['feature'] = 'full';
            }
            // Normal size.
            else {
                $transform['size']['feature'] = 'sizeByWh';
                $transform['size']['width'] = $destinationWidth;
                $transform['size']['height'] = $destinationHeight;
            }
        }

        // "w,h", "w," or ",h".
        else {
            $pos = strpos($size, ',');
            $destinationWidth = (int) substr($size, 0, $pos);
            $destinationHeight = (int) substr($size, $pos + 1);
            if (empty($destinationWidth) && empty($destinationHeight)) {
                $this->_view->setVariable('message', sprintf($this->translate('The Image server cannot fulfill the request: the size "%s" is incorrect.'), $size));
                return null;
            }

            // "w,h": sizeByWhListed or sizeByForcedWh.
            if ($destinationWidth && $destinationHeight) {
                // Check the size only if the region is full, else it's forced.
                if ($transform['region']['feature'] == 'full') {
                    $availableTypes = ['square', 'medium', 'large', 'original'];
                    foreach ($availableTypes as $imageType) {
                        $filepath = $this->_getImagePath($media, $imageType);
                        if ($filepath) {
                            $imageSize = $this->imageSize($media, $imageType);
                            list($testWidth, $testHeight) = $imageSize ? array_values($imageSize) : [null, null];
                            if ($destinationWidth == $testWidth && $destinationHeight == $testHeight) {
                                $transform['size']['feature'] = 'full';
                                // Change the source file to avoid a transformation.
                                // TODO Check the format?
                                if ($imageType != 'original') {
                                    $transform['source']['filepath'] = $filepath;
                                    $transform['source']['media_type'] = 'image/jpeg';
                                    $transform['source']['width'] = $testWidth;
                                    $transform['source']['height'] = $testHeight;
                                }
                                break;
                            }
                        }
                    }
                }
                if (empty($transform['size']['feature'])) {
                    $transform['size']['feature'] = 'sizeByForcedWh';
                    $transform['size']['width'] = $destinationWidth;
                    $transform['size']['height'] = $destinationHeight;
                }
            }

            // "w,": sizeByW.
            elseif ($destinationWidth && empty($destinationHeight)) {
                $transform['size']['feature'] = 'sizeByW';
                $transform['size']['width'] = $destinationWidth;
            }

            // ",h": sizeByH.
            elseif (empty($destinationWidth) && $destinationHeight) {
                $transform['size']['feature'] = 'sizeByH';
                $transform['size']['height'] = $destinationHeight;
            }

            // Not supported.
            else {
                $this->_view->setVariable('message', sprintf($this->translate('The Image server cannot fulfill the request: the size "%s" is not supported.'), $size));
                return null;
            }

            // A quick check to avoid a possible transformation.
            if (isset($transform['size']['width']) && empty($transform['size']['width'])
                    || isset($transform['size']['height']) && empty($transform['size']['height'])
                ) {
                $this->_view->setVariable('message', sprintf($this->translate('The Image server cannot fulfill the request: the size "%s" is not supported.'), $size));
                return null;
            }
        }

        // Determine the mirroring and the rotation.

        $transform['mirror']['feature'] = substr($rotation, 0, 1) === '!' ? 'mirror' : 'default';
        if ($transform['mirror']['feature'] != 'default') {
            $rotation = substr($rotation, 1);
        }

        // Strip leading and ending zeros.
        if (strpos($rotation, '.') === false) {
            $rotation += 0;
        }
        // This may be a float, so keep all digits, because they can be managed
        // by the image server.
        else {
            $rotation = trim($rotation, '0');
            $rotationDotPos = strpos($rotation, '.');
            if ($rotationDotPos === strlen($rotation)) {
                $rotation = (int) trim($rotation, '.');
            } elseif ($rotationDotPos === 0) {
                $rotation = '0' . $rotation;
            }
        }

        // No rotation.
        if (empty($rotation)) {
            $transform['rotation']['feature'] = 'noRotation';
        }

        // Simple rotation.
        elseif ($rotation == 90 || $rotation == 180 || $rotation == 270) {
            $transform['rotation']['feature'] = 'rotationBy90s';
            $transform['rotation']['degrees'] = $rotation;
        }

        // Arbitrary rotation.
        else {
            $transform['rotation']['feature'] = 'rotationArbitrary';
            $transform['rotation']['degrees'] = $rotation;
        }

        // Determine the quality.
        // The regex in route checks it.
        $transform['quality']['feature'] = $quality;

        // Determine the format.
        // The regex in route checks it.
        $mediaTypes = [
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'tif' => 'image/tiff',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'jp2' => 'image/jp2',
            'webp' => 'image/webp',
        ];
        // TODO Check if the tiler support it. Here, the rule is for gd. Manage other libraries.
        // @see https://www.php.net/manual/fr/function.imagetypes.php
        if (in_array($format, ['pdf', 'jp2', 'tif'])) {
            $this->_view->setVariable('message', sprintf($this->translate('The Image server cannot fulfill the request: the format "%s" is not supported.'), $format));
            return null;
        }
        $transform['format']['feature'] = $mediaTypes[$format];

        return $transform;
    }

    /**
     * Get a pre tiled image from Omeka derivatives.
     *
     * Omeka derivative are light and basic pretiled files, that can be used for
     * a request of a full region as a fullsize.
     * @todo To be improved. Currently, thumbnails are not used.
     *
     * @param MediaRepresentation $file
     * @param array $transform
     * @return array|null Associative array with the file path, the derivative
     * type, the width and the height. Null if none.
     */
    protected function _useOmekaDerivative(MediaRepresentation $media, $transform)
    {
        // Some requirements to get tiles.
        if ($transform['region']['feature'] != 'full') {
            return null;
        }

        // Check size. Here, the "full" is already checked.
        $useDerivativePath = false;

        // Currently, the check is done only on fullsize.
        $derivativeType = 'large';
        $imageSize = $this->imageSize($media, $derivativeType);
        list($derivativeWidth, $derivativeHeight) = $imageSize ? array_values($imageSize) : [null, null];
        switch ($transform['size']['feature']) {
            case 'sizeByW':
            case 'sizeByH':
                $constraint = $transform['size']['feature'] == 'sizeByW'
                    ? $transform['size']['width']
                    : $transform['size']['height'];

                // Check if width is lower than fulllsize or thumbnail.
                // Omeka and IIIF doesn't use the same type of constraint, so
                // a double check is done.
                // TODO To be improved.
                if ($constraint <= $derivativeWidth || $constraint <= $derivativeHeight) {
                    $useDerivativePath = true;
                }
                break;

            case 'sizeByWh':
            case 'sizeByWhListed':
            case 'sizeByForcedWh':
                $constraintW = $transform['size']['width'];
                $constraintH = $transform['size']['height'];

                // Check if width is lower than fulllsize or thumbnail.
                if ($constraintW <= $derivativeWidth || $constraintH <= $derivativeHeight) {
                    $useDerivativePath = true;
                }
                break;

            case 'sizeByPct':
                // TODO Check the height too? Anyway, it requires to update the percent in $transform (percent x source width / derivative width).
                if ($transform['size']['percentage'] <= ($derivativeWidth * 100 / $transform['source']['width'])) {
                    // $useDerivativePath = true;
                }
                break;

            case 'full':
                // Not possible to use a derivative, because the region is full.
            default:
                return null;
        }

        if ($useDerivativePath) {
            $derivativePath = $this->_getImagePath($media, $derivativeType);

            return [
                'filepath' => $derivativePath,
                'derivativeType' => $derivativeType,
                'media_type' => 'image/jpeg',
                'width' => $derivativeWidth,
                'height' => $derivativeHeight,
            ];
        }
    }

    /**
     * Get a pre tiled image.
     *
     * @todo Prebuild tiles directly with the IIIF standard (same type of url).
     *
     * @param MediaRepresentation $media
     * @param array $transform
     * @return array|null Associative array with the file path, the derivative
     * type, the width and the height. Null if none.
     */
    protected function _usePreTiled(MediaRepresentation $media, $transform)
    {
        $tileInfo = $this->tileInfo($media);
        if ($tileInfo) {
            return $this->tileServer($tileInfo, $transform);
        }
    }

    /**
     * Transform a file according to parameters.
     *
     * @param array $args Contains the filepath and the parameters.
     * @return string|null The filepath to the temp image if success.
     */
    protected function _transformImage($args)
    {
        $imageServer = new ImageServer($this->tempFileFactory, $this->store, $this->commandLineArgs, $this->settings());
        return $imageServer
            ->setLogger($this->logger())
            ->setTranslator($this->translator)
            ->transform($args);
    }

    /**
     * Get the path to an original or derivative file for an image.
     *
     * @param MediaRepresentation $media
     * @param string $derivativeType
     * @return string|null Null if not exists.
     * @see \ImageServer\View\Helper\IiifInfo::_getImagePath()
     */
    protected function _getImagePath(MediaRepresentation $media, $derivativeType = 'original')
    {
        // Check if the file is an image.
        if (strpos($media->mediaType(), 'image/') === 0) {
            // Don't use the webpath to avoid the transfer through server.
            $filepath = $this->_mediaFilePath($media, $derivativeType);
            if (file_exists($filepath)) {
                return $filepath;
            }

            // Use the web url when an external storage is used. No check can be
            // done.
            // TODO Load locally the external path? It will be done later.
            return $media->thumbnailUrl($derivativeType);
        }
    }

    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix
     * @param string $name The file name, or basename if extension is passed
     * @param null|string $extension The file extension
     * @return string
     * @todo Refactorize.
     */
    protected function getStoragePath($prefix, $name, $extension = null)
    {
        return sprintf('%s/%s%s', $prefix, $name, $extension ? ".$extension" : null);
    }

    /**
     * @todo Factorize with \IiifServer\Controller\PresentationController::fetchResource()
     *
     * @param string $resourceType
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation|null
     */
    protected function fetchResource($resourceType)
    {
        $id = $this->params('id');

        $useCleanIdentifier = $this->useCleanIdentifier();
        if ($useCleanIdentifier) {
            $getResourceFromIdentifier = $this->viewHelpers()->get('getResourceFromIdentifier');
            return $getResourceFromIdentifier($id, false, $resourceType);
        }

        try {
            return $this->api()->read($resourceType, $id)->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return null;
        }
    }

    protected function useCleanIdentifier()
    {
        return $this->viewHelpers()->has('getResourcesFromIdentifiers')
            && $this->settings()->get('iiifserver_manifest_clean_identifier');
    }

    /**
     * Get the requested version from the headers.
     *
     * @todo Factorize with MediaController::requestedVersion()
     *
     * @return string
     */
    protected function requestedVersion()
    {
        // Check the version from the url first.
        $this->version = $this->params('version');
        if ($this->version === '2' || $this->version === '3') {
            return $this->version;
        }

        $accept = $this->getRequest()->getHeaders()->get('Accept')->toString();
        if (strpos($accept, 'iiif.io/api/image/3/context.json')) {
            $this->version = '3';
        } elseif (strpos($accept, 'iiif.io/api/image/2/context.json')) {
            $this->version = '2';
        } else {
            $this->version = $this->settings()->get('imageserver_info_version', '2') ?: '2';
        }
        return $this->version;
    }

    protected function jsonError(\Exception $exception, $statusCode = 500)
    {
        $this->getResponse()->setStatusCode($statusCode);
        return new JsonModel([
            'status' => 'error',
            'message' => $exception->getMessage(),
        ]);
    }

    protected function viewError(\Exception $exception, $statusCode = 500)
    {
        $this->getResponse()->setStatusCode($statusCode);
        $view = new ViewModel;
        return $view
            ->setTemplate('image-server/image/error')
            ->setVariable('message', $exception->getMessage());
    }
}
