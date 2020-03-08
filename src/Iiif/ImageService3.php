<?php

/*
 * Copyright 2020 Daniel Berthereau
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

namespace ImageServer\Iiif;

use IiifServer\Iiif\AbstractResourceType;
use IiifServer\Iiif\TraitRights;
use Omeka\Api\Representation\MediaRepresentation;
use ImageServer\Mvc\Controller\Plugin\TileInfo;

/**
 *@link https://iiif.io/api/image/3.0/
 */
class ImageService3 extends AbstractResourceType
{
    use TraitImage;
    use TraitRights;

    protected $type = 'ImageService3';

    protected $keys = [
        '@context' => self::REQUIRED,

        // Technical properties.
        'id' => self::REQUIRED,
        'type' => self::REQUIRED,
        'protocol' => self::REQUIRED,
        'profile' => self::REQUIRED,
        'width' => self::REQUIRED,
        'height' => self::REQUIRED,
        // The maxWidth and maxHeight are optional/required together.
        // If only maxWidth is set, maxHeight uses it, but the inverse is not possible.
        'maxWidth' => self::OPTIONAL,
        'maxHeight' => self::OPTIONAL,
        'maxArea' => self::OPTIONAL,

        // Sizes.
        'sizes' => self::OPTIONAL,

        // Tiles.
        'tiles' => self::OPTIONAL,

        // Preferred formats.
        'preferredFormats' => self::OPTIONAL,

        // Rights.
        'rights' => self::OPTIONAL,

        // Extra functionality.
        'extraQualities' => self::OPTIONAL,
        'extraFormats' => self::OPTIONAL,
        'extraFeatures' => self::OPTIONAL,

        // Linking properties.
        'partOf' => self::OPTIONAL,
        'seeAlso' => self::OPTIONAL,
        'service' => self::OPTIONAL,
    ];

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $resource;

    public function __construct(MediaRepresentation $resource, array $options = null)
    {
        parent::__construct($resource, $options);
        // TODO Use subclass to manage image or media. Currently, only image.
        $this->initImage();
    }

    public function isImage()
    {
        return true;
    }

    /**
     * @todo Manage extensions.
     *
     * {@inheritDoc}
     * @see \IiifServer\Iiif\AbstractResourceType::getContext()
     */
    public function getContext()
    {
        return 'http://iiif.io/api/image/3/context.json';
    }

    public function getLabel()
    {
        // There is no label for an image.
        return null;
    }

    public function getId()
    {
        $helper = $this->urlHelper;
        $url = $helper(
            'imageserver/id',
            [
                'id' => $this->resource->id(),
            ],
            ['force_canonical' => true]
        );
        $helper = $this->iiifForceBaseUrlIfRequired;
        return $helper($url);
    }

    public function getProtocol()
    {
        return 'http://iiif.io/api/image';
    }

    public function getProfile()
    {
        return 'level2';
    }

    public function getMaxWidth()
    {
        return $this->getWidth();
    }

    public function getMaxHeight()
    {
        return $this->getHeight();
    }

    public function getMaxArea()
    {
        $size = $this->imageSize();
        return $size
            ? $size['width'] * $size['height']
            : null;
    }

    public function getSizes()
    {
        if (!$this->isImage()) {
            return null;
        }

        $sizes = [];

        // TODO Use the config for specific types.
        $imageTypes = ['medium', 'large', 'original'];
        foreach ($imageTypes as $imageType) {
            $imageSize = new Size($this->resource, ['image_type' => $imageType]);
            if ($imageSize->hasSize()) {
                $sizes[] = $imageSize;
            }
        }

        return $sizes;
    }

    public function getTiles()
    {
        if (!$this->isImage()) {
            return null;
        }

        $tiles = [];

        // TODO Use a standard json-serializable TileInfo.
        $tileInfo = new TileInfo();
        $tilingData = $tileInfo($this->resource);
        if ($tilingData) {
            $iiifTileInfo = new Tile($this->resource, ['tilingData' => $tilingData]);
            if ($iiifTileInfo->hasTilingInfo()) {
                $tiles[] = $iiifTileInfo;
            }
        }

        return $tiles;
    }

    /**
     * The preferred format is jpeg, since the image server uses it by default.
     *
     * @todo Allow to create tiles with webp, gif, and png and add them here.
     *
     * @return array
     */
    public function getPreferredFormats()
    {
        return [
            'jpg',
        ];
    }

    public function getRights()
    {
        $helper = $this->setting;
        $url = null;
        $orUrl = false;

        $param = $helper('imageserver_info_rights');
        switch ($param) {
            case 'url':
                $url = $helper('imageserver_info_rights_url');
                break;
            case 'property_or_url':
                $orUrl = true;
                // no break.
            case 'property':
                $property = $helper('imageserver_info_rights_property');
                $url = (string) $this->resource->value($property);
                break;
            case 'item_or_url':
                $orUrl = true;
                // no break.
            case 'item':
                $url = $this->rightsResource($this->resource->item());
                if ($url || !$orUrl) {
                    return $url;
                }
                break;
            case 'none':
            default:
                return null;
        }

        if (!$url && $orUrl) {
            $url = $helper('imageserver_info_rights_url');
        }

        if ($url) {
            foreach ($this->rightUrls as $rightUrl) {
                if (strpos($url, $rightUrl) === 0) {
                    return $url;
                }
            }
        }

        return null;
    }

    public function getExtraQualities()
    {
        return null;
    }

    public function getExtraFormats()
    {
        return null;
    }

    public function getExtraFeatures()
    {
        // See https://iiif.io/api/image/3/context.json.
        /*
        $support = [
            'baseUriRedirect',
            'canonicalLinkHeader',
            'cors',
            'jsonldMediaType',
            'mirroring',
            'profileLinkHeader',
            'regionByPct',
            'regionByPx',
            'regionSquare',
            'rotationArbitrary',
            'rotationBy90s',
            'sizeByConfinedWh',
            'sizeByH',
            'sizeByPct',
            'sizeByW',
            'sizeByWh',
            'sizeUpscaling',
        ];
        */
        return null;
    }
}
