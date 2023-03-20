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

namespace ImageServer\Mvc\Controller\Plugin;

class TileServerZoomify extends TileServer
{
    /**
     * If there is an overlap, the tile is usually transformed a second time
     * because OpenSeadragon asks for a multiple of the cell size.
     * So the overlap prevents simple redirect and so it is not recommended.
     *
     * {@inheritDoc}
     * @see \ImageServer\Mvc\Controller\Plugin\TileServer::__invoke()
     */
    public function __invoke(array $tileInfo, array $transform): ?array
    {
        if (empty($tileInfo) || $tileInfo['tile_type'] !== 'zoomify') {
            return null;
        }

        // Quick check of supported transformation of tiles.
        // Some formats are managed early and may not be useful here.
        if (
            !in_array($transform['region']['feature'], [
                'full',
                'square',
                'regionByPx',
                'regionByPct',
            ])
            || !in_array($transform['size']['feature'], [
                // In all cases, via controller, width and height are set
                // according to region.
                // Full and max are nearly synonymous.
                'full',
                'max',
                'sizeByH',
                'sizeByW',
                'sizeByWh',
                'sizeByWhListed',
                'sizeByConfinedWh',
                'sizeByForcedWh',
                'sizeByPct',
            ])
        ) {
            return null;
        }

        $cellData = $this->getLevelAndPosition(
            $tileInfo,
            $transform['source'],
            $transform['region'],
            $transform['size'],
            false
        );
        if (is_null($cellData)) {
            return null;
        }

        $imageSize = [
            'width' => $transform['source']['width'],
            'height' => $transform['source']['height'],
        ];
        $tileGroup = $this->getTileGroup($imageSize, $cellData);
        if (is_null($tileGroup)) {
            return null;
        }

        // To manage Windows, the same path cannot be used for url and local.
        $relativeUrl = sprintf(
            'TileGroup%d/%d-%d-%d.jpg',
            $tileGroup,
            $cellData['level'],
            $cellData['column'],
            $cellData['row']
        );
        $relativePath = sprintf(
            'TileGroup%d%s%d-%d-%d.jpg',
            $tileGroup,
            DIRECTORY_SEPARATOR,
            $cellData['level'],
            $cellData['column'],
            $cellData['row']
        );

        // The image url is used when there is no transformation.
        $imageUrl = $tileInfo['url_base']
            . '/' . $tileInfo['media_path']
            . '/' . $relativeUrl;
        $imagePath = $tileInfo['path_base']
            . DIRECTORY_SEPARATOR . $tileInfo['media_path']
            . DIRECTORY_SEPARATOR . $relativePath;

        [$tileWidth, $tileHeight] = array_values($this->getWidthAndHeight($imagePath));

        // TODO To be checked.
        if ($tileInfo['overlap']) {
            $region = [
                'feature' => 'regionByPx',
                'x' => $cellData['isFirstColumn'] ? 0 : $tileInfo['overlap'],
                'y' => $cellData['isFirstRow'] ? 0 : $tileInfo['overlap'],
                'width' => $tileWidth,
                'height' => $tileHeight,
            ];
        }
        // Normal tile.
        else {
            $region = [
                'feature' => 'full',
                'x' => 0,
                'y' => 0,
                'width' => $tileWidth,
                'height' => $tileHeight,
            ];
        }

        $result = [
            'cell' => $cellData,
            'source' => [
                'fileurl' => $imageUrl,
                'filepath' => $imagePath,
                // Useful?
                'derivativeType' => 'tile',
                'media_type' => 'image/jpeg',
                'width' => $tileWidth,
                'height' => $tileHeight,
                'overlap' => $tileInfo['overlap'],
            ],
            // Only full size is supported currently.
            'region' => $region,
            'size' => [
                'feature' => 'max',
            ],
        ];

        return $result;
    }

    /**
     * Return the tile group of a tile from level, position and size.
     *
     * @link https://github.com/openlayers/openlayers/blob/v4.0.0/src/ol/source/zoomify.js
     *
     * @param array $image
     * @param array $tile
     * @return int|null
     */
    protected function getTileGroup(array $image, array $tile): ?int
    {
        if (empty($image) || empty($tile)) {
            return null;
        }

        $tierSizeCalculation = 'default';
        // $tierSizeCalculation = 'truncated';

        $tierSizeInTiles = [];

        switch ($tierSizeCalculation) {
            case 'default':
                $tileSize = $tile['size'];
                while ($image['width'] > $tileSize || $image['height'] > $tileSize) {
                    $tierSizeInTiles[] = [
                        ceil($image['width'] / $tileSize),
                        ceil($image['height'] / $tileSize),
                    ];
                    $tileSize += $tileSize;
                }
                break;

            case 'truncated':
                $width = $image['width'];
                $height = $image['height'];
                while ($width > $tile['size'] || $height > $tile['size']) {
                    $tierSizeInTiles[] = [
                        ceil($width / $tile['size']),
                        ceil($height / $tile['size']),
                    ];
                    $width >>= 1;
                    $height >>= 1;
                }
                break;

            default:
                return null;
        }

        $tierSizeInTiles[] = [1, 1];
        $tierSizeInTiles = array_reverse($tierSizeInTiles);

        $tileCountUpToTier = [0];
        for ($i = 1, $ii = count($tierSizeInTiles); $i < $ii; $i++) {
            $tileCountUpToTier[] =
                $tierSizeInTiles[$i - 1][0] * $tierSizeInTiles[$i - 1][1]
                + $tileCountUpToTier[$i - 1];
        }

        $tileIndex = $tile['column']
            + $tile['row'] * $tierSizeInTiles[$tile['level']][0]
            + $tileCountUpToTier[$tile['level']];
        $tileGroup = ($tileIndex / $tile['size']) ?: 0;
        return (int) $tileGroup;
    }
}
