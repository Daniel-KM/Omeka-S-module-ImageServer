<?php declare(strict_types=1);

/*
 * Copyright 2020-2023 Daniel Berthereau
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

use IiifServer\Iiif\AbstractType;
use Omeka\Api\Representation\MediaRepresentation;

/**
 *@link https://iiif.io/api/image/3.0/#54-tiles
 */
class Tile extends AbstractType
{
    protected $type = 'Tile';

    protected $keys = [
        'type' => self::OPTIONAL,
        'width' => self::REQUIRED,
        'height' => self::OPTIONAL,
        'scaleFactors' => self::REQUIRED,
    ];

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $resource;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var array
     */
    private $tilingInfo;

    public function __construct(MediaRepresentation $resource, array $options = null)
    {
        $this->resource = $resource;
        $this->options = $options ?: [];
        $this->prepareTilingInfo();
    }

    public function isImage(): bool
    {
        return true;
    }

    public function width(): ?int
    {
        return empty($this->tilingInfo)
            ? null
            : $this->tilingInfo['width'];
    }

    /**
     * @todo getHeight() is optional for tile and useless here.
     */
    public function height(): ?int
    {
        return empty($this->tilingInfo)
            ? null
            : $this->tilingInfo['height'] ?? null;
    }

    public function scaleFactors(): ?array
    {
        return empty($this->tilingInfo)
            ? null
            : $this->tilingInfo['scaleFactors'];
    }

    public function hasTilingInfo(): bool
    {
        return !empty($this->tilingInfo);
    }

    protected function prepareTilingInfo(): AbstractType
    {
        if (empty($this->options['tilingData'])) {
            return null;
        }

        $tilingData = &$this->options['tilingData'];

        $scaleFactors = [];
        $maxSize = max($tilingData['source']['width'], $tilingData['source']['height']);
        $tileSize = $tilingData['size'];
        $total = (int) ceil($maxSize / $tileSize);
        $factor = 1;
        while ($factor / 2 <= $total) {
            $scaleFactors[] = $factor;
            $factor = $factor * 2;
        }
        if (count($scaleFactors) <= 1) {
            return null;
        }

        $this->tilingInfo = [];
        $this->tilingInfo['width'] = $tileSize;
        $this->tilingInfo['scaleFactors'] = $scaleFactors;
        return $this;
    }
}
