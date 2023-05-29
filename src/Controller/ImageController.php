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

namespace ImageServer\Controller;

use AccessResource\Mvc\Controller\Plugin\IsForbiddenFile;
use IiifServer\Controller\IiifServerControllerTrait;
use ImageServer\ImageServer\ImageServer;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Stdlib\Message;

/**
 * The Image controller class.
 *
 * @todo Move all image processing stuff in Image Server.
 *
 * @package ImageServer
 */
class ImageController extends AbstractActionController
{
    use IiifServerControllerTrait;

    /**
     * @var \AccessResource\Mvc\Controller\Plugin\IsForbiddenFile
     */
    protected $isForbiddenFile;

    /**
     * @var string
     */
    protected $routeInfo = 'imageserver/info';

    /**
     * Standard and common Iiif image media types.
     *
     * @var array
     */
    protected $mediaTypes = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'tif' => 'image/tiff',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'jp2' => 'image/jp2',
        'webp' => 'image/webp',
    ];

    /**
     * @var ViewModel
     */
    protected $_view;

    public function __construct(
        ?string $basePath,
        ?IsForbiddenFile $isForbiddenFile
    ) {
        $this->basePath = $basePath;
        $this->isForbiddenFile = $isForbiddenFile;
    }

    /**
     * Returns sized image for the current file.
     */
    public function fetchAction()
    {
        /** @var \Omeka\Api\Representation\MediaRepresentation $media */
        $media = $this->fetchResource('media');
        if (!$media) {
            return $this->viewError(new Message(
                'Media "%s" not found.', // @translate
                $this->params('id')
            ), \Laminas\Http\Response::STATUS_CODE_404);
        }

        /**
         * @var \Laminas\Http\PhpEnvironment\Response $response
         * @var \Laminas\Http\Headers $headers
         */
        $response = $this->getResponse();
        $headers = $response->getHeaders();

        // Check if the original file is an image.
        if (strpos($media->mediaType(), 'image/') !== 0) {
            return $this->viewError(new Message(
                'The media "%d" is not an image', // @translate
                $media->id()
            ), \Laminas\Http\Response::STATUS_CODE_501);
        }

        $this->requestedVersionMedia();

        // Compatibility with module AccessResource: rights should be checked
        // for the file, not only for the media
        if ($this->isForbiddenFile
            && !$this->settings()->get('iiifserver_access_resource_skip')
            && $this->isForbiddenFile->__invoke($media)
        ) {
            // Manage custom asset file from the theme.
            $viewHelpers = $this->viewHelpers();
            $assetUrl = $viewHelpers->get('assetUrl');
            $fileUrl = $assetUrl('img/locked-file.png', 'AccessResource', true, true, true);
            $headers
                ->addHeaderLine('Content-Transfer-Encoding: binary')
                // ->addHeaderLine(sprintf('Content-Length: %s', $filesize))
                ->addHeaderLine('Content-Type', 'image/png');

            // Redirect (302/307) to the url of the file.
            return $this->redirect()->toUrl($fileUrl);
        }

        // Check, clean and optimize and fill values according to the request.
        $this->_view = new ViewModel;
        $transform = $this->_cleanRequest($media);
        if (empty($transform)) {
            // The message is set in view.
            $response->setStatusCode(400);
            return $this->_view
                ->setTemplate('iiif-server/error');
        }

        $settings = $this->settings();

        // Now, process the requested transformation if needed.
        $imageUrl = '';
        $imagePath = '';

        // A quick check when there is no transformation.
        if ($transform['region']['feature'] == 'full'
            && $transform['size']['feature'] == 'max'
            && $transform['mirror']['feature'] == 'default'
            && $transform['rotation']['feature'] == 'noRotation'
            && $transform['quality']['feature'] == 'default'
            && $transform['format']['feature'] == $media->mediaType()
        ) {
            // The transform set the source as original or thumbnail url.
            $imageUrl = $transform['source']['type'] === 'original'
                ? $media->originalUrl()
                : $media->thumbnailUrl($transform['source']['type']);
        }

        // A transformation is needed.
        else {
            // Quick check if an Omeka derivative is appropriate.
            $pretiled = $this->_useOmekaDerivative($media, $transform);
            if ($pretiled) {
                // Check if a light transformation is needed.
                if ($transform['size']['feature'] != 'max'
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
                    $imagePath = $this->imageServer()->transform($args);
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
                    if (($pretiled['source']['overlap'] && !$pretiled['cell']['isSingleCell'])
                        || $transform['mirror']['feature'] != 'default'
                        || $transform['rotation']['feature'] != 'noRotation'
                        || $transform['quality']['feature'] != 'default'
                        || $transform['format']['feature'] != $pretiled['source']['media_type']
                    ) {
                        $args = $transform;
                        $args['source'] = $pretiled['source'];
                        $args['region'] = $pretiled['region'];
                        $args['size'] = $pretiled['size'];
                        $imagePath = $this->imageServer()->transform($args);
                    }
                    // No transformation.
                    else {
                        $imageUrl = $pretiled['source']['fileurl'];
                    }
                }

                // The image needs to be transformed dynamically.
                else {
                    // Vips can manage any size instantly, so skip check of it.
                    $imager = $settings->get('imageserver_imager');
                    if ($imager === 'Auto') {
                        /** @var \ImageServer\ImageServer\Auto $imager */
                        $imager = $this->imageServer()->getImager()->getImager($transform);
                        if ($imager && $imager instanceof \ImageServer\ImageServer\Vips) {
                            $imager = 'Vips';
                        }
                    }
                    if ($imager !== 'Vips') {
                        $maxFileSize = $settings->get('imageserver_image_max_size');
                        if (!empty($maxFileSize) && $media->size() > $maxFileSize) {
                            return $this->viewError(new Message(
                                'The Image server encountered an unexpected error that prevented it from fulfilling the request: the file is not tiled for dynamic processing.' // @translate
                            ), \Laminas\Http\Response::STATUS_CODE_500);
                        }
                    }
                    $imagePath = $this->imageServer()->transform($transform);
                }
            }
        }

        // Redirect to the url when an existing file is available.
        if ($imageUrl) {
            // Header for CORS, required for access of IIIF.
            if ($this->settings()->get('iiifserver_manifest_append_cors_headers')) {
                $headers
                    ->addHeaderLine('Access-Control-Allow-Origin', '*');
            }
            $headers
                // Recommanded by feature "profileLinkHeader".
                ->addHeaderLine('Link', version_compare($this->requestedApiVersion, '3', '<')
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
            $filesize = filesize($imagePath);
            if (empty($filesize)) {
                @unlink($imagePath);
                return $this->viewError(new Message(
                    'The Image server encountered an unexpected error that prevented it from fulfilling the request: the resulting file is not found or empty.' // @translate
                ), \Laminas\Http\Response::STATUS_CODE_500);
            }

            // Header for CORS, required for access of IIIF.
            if ($this->settings()->get('iiifserver_manifest_append_cors_headers')) {
                $headers
                    ->addHeaderLine('Access-Control-Allow-Origin', '*');
            }
            $headers
                // Recommanded by feature "profileLinkHeader".
                ->addHeaderLine('Link', version_compare($this->requestedApiVersion, '3', '<')
                    ? '<http://iiif.io/api/image/2/level2.json>;rel="profile"'
                    : '<http://iiif.io/api/image/3/>;rel="profile"'
                )
                ->addHeaderLine('Content-Type', $transform['format']['feature']);

            // Send headers separately to handle large files.
            $response->sendHeaders();

            // TODO Use Laminas stream response.

            // Clears all active output buffers to avoid memory overflow.
            $response->setContent('');
            while (ob_get_level()) {
                ob_end_clean();
            }
            readfile($imagePath);

            // TODO Fix issue with session. See readme of module XmlViewer.
            ini_set('display_errors', '0');

            @unlink($imagePath);

            // Return response to avoid default view rendering and to manage events.
            return $response;
        }

        // No result.
        else {
            return $this->viewError(new Message(
                'The Image server encountered an unexpected error that prevented it from fulfilling the request: the resulting file is empty or not found.' // @translate
            ), \Laminas\Http\Response::STATUS_CODE_500);
        }
    }

    /**
     * Check, clean and optimize the request for quicker transformation.
     *
     * @todo Move the maximum of checks in the Image Server.
     * @todo Use the result to build the canonical link for the header.
     *
     * @param MediaRepresentation $media
     * @return array|null Array of cleaned requested image, else null.
     */
    protected function _cleanRequest(MediaRepresentation $media): ?array
    {
        $transform = [];

        $transform['version'] = $this->requestedApiVersion;

        $transform['source']['type'] = 'original';
        $transform['source']['filepath'] = $this->_getImagePath($media, 'original');
        $transform['source']['media_type'] = $media->mediaType();

        $imageSize = $this->imageSize($media, 'original');
        $sourceWidth = $imageSize['width'];
        $sourceHeight = $imageSize['height'];
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
        // The region is simplified into "regionByPx" or "full" in all cases.

        // Full image.
        // Manage the case where the source and requested images are square too.
        // TODO Square is not supported by 2.0, only by 2.1, but the iiif validator bypasses it.
        if ($region == 'full' || ($region === 'square' && $sourceWidth === $sourceHeight)) {
            $transform['region'] = [
                'feature' => 'full',
                'x' => 0,
                'y' => 0,
                'width' => $sourceWidth,
                'height' => $sourceHeight,
            ];
        }

        // Square image.
        elseif ($region == 'square') {
            if ($sourceWidth > $sourceHeight) {
                $transform['region'] = [
                    'feature' => 'regionByPx',
                    'x' => (int) (($sourceWidth - $sourceHeight) / 2),
                    'y' => 0,
                    'width' => $sourceHeight,
                    'height' => $sourceHeight,
                ];
            } else {
                $transform['region'] = [
                    'feature' => 'regionByPx',
                    'x' => 0,
                    'y' => (int) (($sourceHeight - $sourceWidth) / 2),
                    'width' => $sourceWidth,
                    'height' => $sourceWidth,
                ];
            }
        }

        // "pct:x,y,w,h": regionByPct
        elseif (strpos($region, 'pct:') === 0) {
            $regionValues = explode(',', substr($region, 4));
            if (count($regionValues) !== 4) {
                $this->_view->setVariable('message', sprintf(
                    $this->translate('The Image server cannot fulfill the request: the region "%s" is incorrect.'), // @translate
                    $region
                ));
                return null;
            }
            $regionValues = array_map('floatval', $regionValues);
            // A quick check to avoid a possible transformation.
            if ($regionValues[0] == 0
                && $regionValues[1] == 0
                && $regionValues[2] == 100
                && $regionValues[3] == 100
            ) {
                $transform['region'] = [
                    'feature' => 'full',
                    'x' => 0,
                    'y' => 0,
                    'width' => $sourceWidth,
                    'height' => $sourceHeight,
                ];
            }
            // Normal region.
            else {
                $regionX = $regionValues[0];
                $regionY = $regionValues[1];
                $regionWidth = $regionValues[2];
                $regionHeight = $regionValues[3];
                $x = $sourceWidth * $regionX / 100;
                $y = $sourceHeight * $regionY / 100;
                // Don't return x or y greater than source.
                if ($x > $sourceWidth || $y > $sourceHeight) {
                    $this->_view->setVariable('message', sprintf(
                        $this->translate('The Image server cannot fulfill the request: the region "%s" is  outside the source.'), // @translate
                        $region
                    ));
                    return null;
                }
                $width = ($regionX + $regionWidth) <= 100
                    ? $sourceWidth * $regionWidth / 100
                    : $sourceWidth - $x;
                $height = ($regionY + $regionHeight) <= 100
                    ? $sourceHeight * $regionHeight / 100
                    : $sourceHeight - $y;
                $transform['region'] = [
                    'feature' => 'regionByPx',
                    'x' => (int) $x,
                    'y' => (int) $y,
                    'width' => (int) $width,
                    'height' => (int) $height,
                ];
                if ($transform['region']['x'] === 0
                    && $transform['region']['y'] === 0
                    && $transform['region']['width'] === $sourceWidth
                    && $transform['region']['height'] === $sourceHeight
                ) {
                    $transform['region']['feature'] = 'full';
                }
            }
        }

        // "x,y,w,h": regionByPx.
        else {
            $regionValues = explode(',', $region);
            if (count($regionValues) != 4) {
                $this->_view->setVariable('message', sprintf(
                    $this->translate('The Image server cannot fulfill the request: the region "%s" is incorrect.'), // @translate
                    $region
                ));
                return null;
            }
            $regionValues = array_map('intval', $regionValues);
            // A quick check to avoid a possible transformation.
            if ($regionValues[0] == 0
                && $regionValues[1] == 0
                && $regionValues[2] == $sourceWidth
                && $regionValues[3] == $sourceHeight
            ) {
                $transform['region'] = [
                    'feature' => 'full',
                    'x' => 0,
                    'y' => 0,
                    'width' => $sourceWidth,
                    'height' => $sourceHeight,
                ];
            }
            // Normal region.
            else {
                // Don't return x or y greater than source.
                $regionX = $regionValues[0];
                $regionY = $regionValues[1];
                if ($regionX > $sourceWidth || $regionY > $sourceHeight) {
                    $this->_view->setVariable('message', sprintf(
                        $this->translate('The Image server cannot fulfill the request: the region "%s" is  outside the source.'), // @translate
                        $region
                    ));
                    return null;
                }
                $regionWidth = $regionValues[2];
                $regionHeight = $regionValues[3];
                $x = $regionX;
                $y = $regionY;
                $width = ($x + $regionWidth) <= $sourceWidth
                    ? $regionWidth
                    : $sourceWidth - $x;
                $height = ($y + $regionHeight) <= $sourceHeight
                    ? $regionHeight
                    : $sourceHeight - $y;
                $transform['region'] = [
                    'feature' => 'regionByPx',
                    'x' => (int) $x,
                    'y' => (int) $y,
                    'width' => (int) $width,
                    'height' => (int) $height,
                ];
                if ($transform['region']['x'] === 0
                    && $transform['region']['y'] === 0
                    && $transform['region']['width'] === $sourceWidth
                    && $transform['region']['height'] === $sourceHeight
                ) {
                    $transform['region']['feature'] = 'full';
                }
            }
        }

        // Check sizes to avoid a possible issue.
        if ($transform['region']['x'] < 0
            || $transform['region']['y'] < 0
            || $transform['region']['width'] <= 0
            || $transform['region']['height'] <= 0
        ) {
            $this->_view->setVariable('message', sprintf(
                $this->translate('The Image server cannot fulfill the request: the region"%s" is invalid.'), // @translate
                $region
            ));
            return null;
        }

        // Determine the size.
        // The width and height are set in all cases, so it is useless to check
        // the feature in most of the case.
        // TODO Clarify feature names (even if useless now since width and height are always determined early).
        // TODO Manage upscaling via a parameter in config.

        // Manage the main difference between version 2 and 3.
        $upscale = mb_substr($size, 0, 1) === '^';
        $versionIsGreaterOrEqual3 = version_compare($this->requestedApiVersion, '3', '>=');
        if ($upscale && !$versionIsGreaterOrEqual3) {
            $this->_view->setVariable('message', sprintf(
                $this->translate('The Image server cannot fulfill the request: the size "%s" is incorrect for API version %s.'), // @translate
                $size,
                $this->requestedApiVersion
            ));
            return null;
        }

        // Full image.
        elseif ($size === 'full') {
            // This value is not allowed in version 3.
            if ($versionIsGreaterOrEqual3) {
                $this->_view->setVariable('message', sprintf(
                    $this->translate('The Image server cannot fulfill the request: the size "%s" is incorrect.'), // @translate
                    $size
                ));
                return null;
            }
            $transform['size'] = [
                'feature' => 'max',
                'upscale' => $upscale,
                'width' => $transform['region']['width'],
                'height' => $transform['region']['height'],
            ];
        }

        // Max image (but below the max of the server).
        elseif ($size === 'max') {
            $transform['size'] = [
                'feature' => 'max',
                'upscale' => $upscale,
                'width' => $transform['region']['width'],
                'height' => $transform['region']['height'],
            ];
        }

        // TODO Currently, the module doesn't set any max size, so max is full.
        elseif ($size === '^max') {
            $transform['size'] = [
                'feature' => 'max',
                'upscale' => $upscale,
                'width' => $transform['region']['width'],
                'height' => $transform['region']['height'],
            ];
        }

        // "pct:x": sizeByPct
        elseif (strpos($size, 'pct:') === 0 || strpos($size, '^pct:') === 0) {
            $sizePercentage = floatval(substr($size, $upscale ? 5 : 4));
            if (empty($sizePercentage)) {
                $this->_view->setVariable('message', sprintf(
                    $this->translate('The Image server cannot fulfill the request: the size "%s" is incorrect.'), // @translate
                    $size
                ));
                return null;
            }
            // A quick check to avoid a possible transformation.
            if ($sizePercentage == 100) {
                // TODO Manage upscaling.
                $transform['size'] = [
                    'feature' => 'max',
                    'upscale' => $upscale,
                    'width' => $transform['region']['width'],
                    'height' => $transform['region']['height'],
                ];
            }
            // Normal size.
            else {
                $transform['size'] = [
                    'feature' => 'sizeByWh',
                    'upscale' => $upscale,
                    'width' => (int) ($transform['region']['width'] * $sizePercentage / 100),
                    'height' => (int) ($transform['region']['height'] * $sizePercentage / 100),
                ];
                // TODO Manage max upscale.
            }
        }

        // "!w,h": sizeByConfinedWh (keep ratio), with or without upscale.
        elseif (strpos($size, '!') === 0 || strpos($size, '^!') === 0) {
            $pos = strpos($size, ',');
            $destinationWidth = (int) substr($size, $upscale ? 2 : 1, $pos);
            $destinationHeight = (int) substr($size, $pos + 1);
            if (empty($destinationWidth) || empty($destinationHeight)) {
                $this->_view->setVariable('message', sprintf(
                    $this->translate('The Image server cannot fulfill the request: the size "%s" is incorrect.'), // @translate
                    $size
                ));
                return null;
            }

            // A quick check to avoid a possible transformation.
            if (($destinationWidth >= $transform['region']['width'] && $destinationHeight == $transform['region']['height'])
                || ($destinationWidth == $transform['region']['width'] && $destinationHeight >= $transform['region']['height'])
            ) {
                $transform['size'] = [
                    'feature' => 'max',
                    'upscale' => $upscale,
                    'width' => $transform['region']['width'],
                    'height' => $transform['region']['height'],
                ];
            } else {
                $pctWidth = $transform['region']['width'] / $destinationWidth;
                $pctHeight = $transform['region']['height'] / $destinationHeight;
                if ($pctWidth > $pctHeight) {
                    $transform['size'] = [
                        'feature' => 'sizeByConfinedWh',
                        'upscale' => $upscale,
                        'width' => (int) ($transform['region']['width'] * $pctHeight),
                        'height' => $destinationHeight,
                    ];
                } elseif ($pctWidth < $pctHeight) {
                    $transform['size'] = [
                        'feature' => 'sizeByConfinedWh',
                        'upscale' => $upscale,
                        'width' => $destinationWidth,
                        'height' => (int) ($transform['region']['height'] * $pctWidth),
                    ];
                } else {
                    $transform['size'] = [
                        'feature' => 'sizeByConfinedWh',
                        'upscale' => $upscale,
                        'width' => $destinationWidth,
                        'height' => $destinationHeight,
                    ];
                }
            }
        }

        // "w,h" (no ratio check), "w," or ",h" (keep ratio) with upscale or not.
        else {
            $pos = strpos($size, ',');
            $destinationWidth = (int) substr($size, $upscale ? 1 : 0, $pos);
            $destinationHeight = (int) substr($size, $pos + 1);
            if (empty($destinationWidth) && empty($destinationHeight)) {
                $this->_view->setVariable('message', sprintf(
                    $this->translate('The Image server cannot fulfill the request: the size "%s" is incorrect.'), // @translate
                    $size
                ));
                return null;
            }

            // "w,h": sizeByWhListed or sizeByForcedWh (no ratio check).
            if ($destinationWidth && $destinationHeight) {
                // Check the size only if the region is full, else it's forced.
                if ($transform['region']['feature'] == 'full') {
                    $availableTypes = ['square', 'medium', 'large', 'original'];
                    foreach ($availableTypes as $imageType) {
                        $filepath = $this->_getImagePath($media, $imageType);
                        if ($filepath) {
                            $imageSize = $this->imageSize($media, $imageType);
                            if ($destinationWidth == $imageSize['width'] && $destinationHeight == $imageSize['height']) {
                                $transform['size']['feature'] = 'max';
                                $transform['size']['upscale'] = $upscale;
                                $transform['size']['width'] = $destinationWidth;
                                $transform['size']['height'] = $destinationHeight;
                                // Change the source file to avoid a transformation.
                                // TODO Check the format?
                                if ($imageType != 'original') {
                                    $transform['source']['type'] = $imageType;
                                    $transform['source']['filepath'] = $filepath;
                                    $transform['source']['media_type'] = 'image/jpeg';
                                    $transform['source']['width'] = $imageSize['width'];
                                    $transform['source']['height'] = $imageSize['height'];
                                    $transform['region']['x'] = 0;
                                    $transform['region']['y'] = 0;
                                    $transform['region']['width'] = $imageSize['width'];
                                    $transform['region']['height'] = $imageSize['height'];
                                }
                                break;
                            }
                        }
                    }
                }
                if (empty($transform['size']['feature'])) {
                    $transform['size'] = [
                        'feature' => 'sizeByForcedWh',
                        'upscale' => $upscale,
                        'width' => $destinationWidth,
                        'height' => $destinationHeight,
                    ];
                }
            }

            // "w,": sizeByW (keep ratio).
            elseif ($destinationWidth && empty($destinationHeight)) {
                $pctWidth = $transform['region']['width'] / $destinationWidth;
                $transform['size'] = [
                    'feature' => 'sizeByW',
                    'upscale' => $upscale,
                    'width' => $destinationWidth,
                    'height' => (int) ($transform['region']['height'] * $pctWidth),
                ];
            }

            // ",h": sizeByH (keep ratio).
            elseif (empty($destinationWidth) && $destinationHeight) {
                $pctHeight = $transform['region']['height'] / $destinationHeight;
                $transform['size'] = [
                    'feature' => 'sizeByH',
                    'upscale' => $upscale,
                    'width' => (int) ($transform['region']['width'] * $pctHeight),
                    'height' => $destinationHeight,
                ];
            }

            // Not supported.
            else {
                $this->_view->setVariable('message', sprintf(
                    $this->translate('The Image server cannot fulfill the request: the size "%s" is not supported.'), // @translate
                    $size
                ));
                return null;
            }
        }

        // Check sizes to avoid a possible issue.
        if ($transform['size']['width'] <= 0
            || $transform['size']['height'] <= 0
        ) {
            $this->_view->setVariable('message', sprintf(
                $this->translate('The Image server cannot fulfill the request: the size "%s" is invalid.'), // @translate
                $size
            ));
            return null;
        }

        // Check strict upscale of api v3.
        if (!$upscale && $versionIsGreaterOrEqual3
            && ($destinationWidth > $transform['region']['width'] || $destinationHeight > $transform['region']['height'])
        ) {
            $this->_view->setVariable('message', sprintf(
                $this->translate('The Image server cannot fulfill the request: the region "%1$s" or size "%2$s" is incorrect for api version %3$s.'), // @translate
                $region,
                $size,
                $this->requestedApiVersion
            ));
            return null;
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

        // Determine the format. The regex in route checks it first.
        // @see https://www.php.net/manual/fr/function.imagetypes.php
        if (!isset($this->mediaTypes, $format)) {
            /** @var \ImageServer\ImageServer\AbstractImager $imager */
            $imager = $this->imageServer()->getImager();
            if (!$imager->checkExtension($format)) {
                $this->_view->setVariable('message', sprintf(
                    $this->translate('The Image server cannot fulfill the request: the format "%s" is not supported.'), // @translate
                    $format
                ));
                return null;
            }
            $transform['format']['feature'] = $imager->getMediaTypeFromExtension($format);
            return $transform;
        }

        $transform['format']['feature'] = $this->mediaTypes[$format] ?? $format;
        return $transform;
    }

    /**
     * Get a pre tiled image from Omeka derivatives.
     *
     * Omeka derivative are light and basic pretiled files, that can be used for
     * a request of a full region as a fullsize.
     * @todo To be improved. Currently, thumbnails are used only with exact sizes.
     *
     * @param MediaRepresentation $file
     * @param array $transform
     * @return array|null Associative array with the file path, the derivative
     * type, the width and the height. Null if none.
     */
    protected function _useOmekaDerivative(MediaRepresentation $media, array $transform): ?array
    {
        // Some requirements to get tiles.
        if ($transform['region']['feature'] != 'full') {
            return null;
        }

        // Check size. Here, the "full" is already checked.
        $useDerivativePath = false;

        // Currently, the check is not done on square and original sizes.
        $availableTypes = ['medium', 'large'];
        foreach ($availableTypes as $derivativeType) {
            $imageSize = $this->imageSize($media, $derivativeType);
            $derivativeWidth = $imageSize['width'];
            $derivativeHeight = $imageSize['height'];
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
                        break 2;
                    }
                    break;

                case 'sizeByWh':
                case 'sizeByConfinedWh':
                case 'sizeByWhListed':
                case 'sizeByForcedWh':
                    $constraintW = $transform['size']['width'];
                    $constraintH = $transform['size']['height'];

                    // Check if width is lower than fulllsize or thumbnail.
                    if ($constraintW <= $derivativeWidth || $constraintH <= $derivativeHeight) {
                        $useDerivativePath = true;
                        break 2;
                    }
                    break;

                case 'sizeByPct':
                    // TODO Check the height too? Anyway, it requires to update the percent in $transform (percent x source width / derivative width).
                    if ($transform['size']['percentage'] <= ($derivativeWidth * 100 / $transform['source']['width'])) {
                        // $useDerivativePath = true;
                    }
                    break;

                case 'full':
                case 'max':
                    // The derivative type is already checked in the previous step,
                    // so source and region contain already the derivative file path
                    // and size.
                    if ($derivativeType === $transform['source']['type']) {
                        $useDerivativePath = true;
                        break 2;
                    }
                    break;

                default:
                    break;
            }
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

        return null;
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
    protected function _usePreTiled(MediaRepresentation $media, array $transform): ?array
    {
        $tileInfo = $this->tileMediaInfo($media);
        return $tileInfo
            ? $this->tileServer($tileInfo, $transform)
            : null;
    }

    protected function _mediaPath(MediaRepresentation $media, string $imageType = 'original'): string
    {
        $storagePath = $imageType == 'original'
            ? $this->getStoragePath($imageType, $media->filename())
            : $this->getStoragePath($imageType, $media->storageId(), 'jpg');
        $filepath = $this->basePath . DIRECTORY_SEPARATOR . $storagePath;
        if (file_exists($filepath) && is_readable($filepath)) {
            return $filepath;
        }
        return $imageType == 'original'
            ? $media->originalUrl()
            : $media->thumbnailUrl($imageType);
    }

    /**
     * Get path to an original or derivative file for an image. May be a url.
     *
     * @param MediaRepresentation $media
     * @param string $imageType
     * @return string|null Null if not exists.
     */
    protected function _getImagePath(MediaRepresentation $media, string $imageType = 'original'): ?string
    {
        return strpos($media->mediaType(), 'image/') === 0
            ? $this->_mediaPath($media, $imageType)
            : null;
    }
}
