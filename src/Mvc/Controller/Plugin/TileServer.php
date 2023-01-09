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

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class TileServer extends AbstractPlugin
{
    /**
     * Retrieve tiles for an image, if any, according to the required transform.
     *
     * This tile server returns only the tiles created by the tiler. Optional
     * transformation is done dynamically. Request of tiles bigger than a tile
     * at any level should be done dynamically.
     *
     * @todo For non standard requests, the tiled images may be used to rebuild
     * a fullsize image that is larger the Omeka derivatives. In that case,
     * multiple tiles should be joined. Use vips.
     *
     * Because the position of the requested region may be anything (it depends
     * of the client), until four images may be needed to build the resulting
     * image. It may be quicker to reassemble them rather than extracting the
     * part from the full image, specially with big ones (except with jpeg2000).
     * Nevertheless, OpenSeadragon tries to ask 0-based tiles, so only this case
     * is managed currently.
     *
     * @param array $tileInfo
     * @param array $transform
     * @return array|null
     */
    public function __invoke(array $tileInfo, array $transform): ?array
    {
        if (empty($tileInfo) || empty($tileInfo['tile_type'])) {
            return null;
        }

        switch ($tileInfo['tile_type']) {
            case 'deepzoom':
                return $this->getController()->tileServerDeepZoom($tileInfo, $transform);
            case 'zoomify':
                return $this->getController()->tileServerZoomify($tileInfo, $transform);
            default:
                return null;
        }
    }

    protected function convertRegionByPercent(array $source, array $region): array
    {
        $x = $source['width'] * $region['x'] / 100;
        $y = $source['height'] * $region['y'] / 100;
        $width = ($region['x'] + $region['width']) <= 100
            ? $source['width'] * $region['width'] / 100
            : $source['width'] - $x;
        $height = ($region['y'] + $region['height']) <= 100
            ? $source['height'] * $region['height'] / 100
            : $source['height'] - $y;
        return [
            'feature' => 'regionByPx',
            'x' => (int) $x,
            'y' => (int) $y,
            'width' => (int) $width,
            'height' => (int) $height,
        ];
    }

    /**
     * Get the level and the position of the cell from the source and region.
     *
     * @param array $tileInfo
     * @param array $source
     * @param array $region
     * @param array $size
     * @param bool $isOneBased True if the pyramid starts at 1x1, or false if
     * it starts at the tile size.
     * @return array|null
     */
    protected function getLevelAndPosition(
        array $tileInfo,
        array $source,
        array $region,
        array $size,
        bool $isOneBased
    ): ?array {
        // Initialize with default values.
        $level = 0;
        $cellX = 0;
        $cellY = 0;
        // TODO A bigger size can be requested directly, and, in that case,
        // multiple tiles should be joined. Currently managed via the dynamic
        // processor.
        $cellSize = $tileInfo['size'];

        if ($region['feature'] === 'regionByPct') {
            $region = $this->convertRegionByPercent($source, $region);
        }

        // Return only direct single tile, or smaller.
        $isNotTile = $region['x'] % $cellSize !== 0 || $region['y'] % $cellSize !== 0;
        if ($isNotTile) {
            return null;
        }

        // Check if the tile may be cropped.
        $isFirstColumn = $region['x'] == 0;
        $isFirstRow = $region['y'] == 0;
        $isFirstCell = $isFirstColumn && $isFirstRow;
        $isLastColumn = $source['width'] <= ($region['x'] + $region['width']);
        $isLastRow = $source['height'] <= ($region['y'] + $region['height']);
        $isLastCell = $isLastColumn && $isLastRow;
        $isSingleCell = $isFirstCell && $isLastCell;

        if ($isSingleCell) {
            // The whole image should be returned, so only check the biggest
            // whole image. With Zoomify, image is always "TileGroup0/0-0-0.jpg".
            // So only check the requested size.
            // Inside Omeka, it is never the case, because the thumbnails are
            // already returned.
            switch ($size['feature']) {
                case 'sizeByW':
                    if ($size['width'] > $cellSize
                        || ($source['height'] * $size['width'] * $source['width'] > $cellSize)
                    ) {
                        return null;
                    }
                    break;

                case 'sizeByH':
                    if ($size['height'] > $cellSize
                        || ($source['width'] * $size['height'] / $source['height'] > $cellSize)
                    ) {
                        return null;
                    }
                    break;

                case 'sizeByConfinedWh':
                case 'sizeByWh':
                case 'sizeByWhListed':
                    if ($size['width'] > $cellSize
                        || ($source['height'] * $size['width'] * $source['width'] > $cellSize)
                        || $size['height'] > $cellSize
                        || ($source['width'] * $size['height'] / $source['height'] > $cellSize)
                    ) {
                        return null;
                    }
                    break;

                case 'full':
                case 'max':
                    if ($size['width'] > $cellSize
                        || $size['height'] > $cellSize
                    ) {
                        return null;
                    }
                    break;

                default:
                    return null;
            }
        } else {
            // Determine the position of the cell from the source and the
            // region.
            switch ($size['feature']) {
                case 'sizeByW':
                    if ($isLastColumn) {
                        // Normal row. The last cell is an exception.
                        if (!$isLastCell) {
                            // Use row, because Deepzoom and Zoomify tiles are
                            // square by default.
                            // TODO Manage the case where tiles are not square.
                            $count = (int) ceil(max($source['width'], $source['height']) / $region['height']);
                            $cellX = $region['x'] / $region['height'];
                            $cellY = $region['y'] / $region['height'];
                        }
                    }
                    // Normal column and normal region.
                    else {
                        $count = (int) ceil(max($source['width'], $source['height']) / $region['width']);
                        $cellX = $region['x'] / $region['width'];
                        $cellY = $region['y'] / $region['width'];
                    }
                    break;

                case 'sizeByH':
                    if ($isLastRow) {
                        // Normal column. The last cell is an exception.
                        if (!$isLastCell) {
                            // Use column, because tiles are square.
                            $count = (int) ceil(max($source['width'], $source['height']) / $region['width']);
                            $cellX = $region['x'] / $region['width'];
                            $cellY = $region['y'] / $region['width'];
                        }
                    }
                    // Normal row and normal region.
                    else {
                        $count = (int) ceil(max($source['width'], $source['height']) / $region['height']);
                        $cellX = $region['x'] / $region['height'];
                        $cellY = $region['y'] / $region['height'];
                    }
                    break;

                case 'sizeByPct':
                    // TODO Manage sizeByPct by tile server.
                    break;

                case 'sizeByConfinedWh':
                    // TODO Manage sizeByConfinedWh by tile server.
                    break;

                case 'sizeByForcedWh':
                    // TODO Manage sizeByForcedWh by tile server.
                    break;

                case 'sizeByWh':
                case 'sizeByWhListed':
                    // TODO To improve.
                    if ($isLastColumn) {
                        // Normal row. The last cell is an exception.
                        if (!$isLastCell) {
                            // Use row, because tiles are square.
                            $count = (int) ceil(max($source['width'], $source['height']) / $region['height']);
                            $cellX = $region['x'] / $region['width'];
                            $cellY = $region['y'] / $region['height'];
                        }
                    }
                    // Normal column and normal region.
                    else {
                        $count = (int) ceil(max($source['width'], $source['height']) / $region['width']);
                        $cellX = $region['x'] / $region['width'];
                        $cellY = $region['y'] / $region['height'];
                    }
                    break;

                case 'full':
                case 'max':
                    // TODO To be checked.
                    // Normalize the size, but they can be cropped.
                    $size['width'] = $region['width'];
                    $size['height'] = $region['height'];
                    $count = (int) ceil(max($source['width'], $source['height']) / $region['width']);
                    $cellX = $region['x'] / $region['width'];
                    $cellY = $region['y'] / $region['height'];
                    break;

                default:
                    return null;
            }

            // Get the list of squale factors.
            $maxDimension = max([$source['width'], $source['height']]);
            $numLevels = $this->getNumLevels($maxDimension);
            // In IIIF, levels start at the tile size.
            $numLevels -= (int) log($cellSize, 2);
            $scaleFactors = $this->getScaleFactors($numLevels);
            // TODO Find why maxSize and total were needed.
            // $maxSize = max($source['width'], $source['height']);
            // $total = (int) ceil($maxSize / $tileInfo['size']);
            // If level is set, count is not set and useless.
            $level = isset($level) ? $level : 0;
            $count = isset($count) ? $count : 0;
            foreach ($scaleFactors as $scaleFactor) {
                if ($scaleFactor >= $count) {
                    break;
                }
                ++$level;
            }

            // Process the last cell, an exception because it may be cropped.
            if ($isLastCell) {
                // TODO Quick check if the last cell is a standard one (not cropped)?
                // Because the default size of the region lacks, it is simpler
                // to check if an image of the zoomed file is the same using the
                // tile size from properties, for each possible factor.
                $reversedScaleFactors = array_reverse($scaleFactors);
                $isLevelFound = false;
                foreach ($reversedScaleFactors as $level => $reversedFactor) {
                    $tileFactor = $reversedFactor * $tileInfo['size'];
                    $countX = (int) ceil($source['width'] / $tileFactor);
                    $countY = (int) ceil($source['height'] / $tileFactor);
                    $lastRegionWidth = $source['width'] - (($countX - 1) * $tileFactor);
                    $lastRegionHeight = $source['height'] - (($countY - 1) * $tileFactor);
                    $lastRegionX = $source['width'] - $lastRegionWidth;
                    $lastRegionY = $source['height'] - $lastRegionHeight;
                    if ($region['x'] == $lastRegionX
                        && $region['y'] == $lastRegionY
                        && $region['width'] == $lastRegionWidth
                        && $region['height'] == $lastRegionHeight
                    ) {
                        // Level is found.
                        $isLevelFound = true;
                        // Cells are 0-based.
                        $cellX = $countX - 1;
                        $cellY = $countY - 1;
                        break;
                    }
                }
                if (!$isLevelFound) {
                    return null;
                }
            }
        }

        if ($isOneBased) {
            $level += (int) log($cellSize, 2);
        }

        return [
            'level' => $level,
            'column' => $cellX,
            'row' => $cellY,
            'size' => $cellSize,
            'isFirstColumn' => $isFirstColumn,
            'isFirstRow' => $isFirstRow,
            'isFirstCell' => $isFirstCell,
            'isLastColumn' => $isLastColumn,
            'isLastRow' => $isLastRow,
            'isLastCell' => $isLastCell,
            'isSingleCell' => $isSingleCell,
        ];
    }

    /**
     * Get the number of levels in the pyramid (first level has a size of 1x1).
     *
     * @param int $maxDimension
     * @return int
     */
    protected function getNumLevels($maxDimension): int
    {
        return (int) ceil(log($maxDimension, 2)) + 1;
    }

    /**
     * Get the scale factors.
     *
     * Note: the check of the number of levels (1-based or tile based) should be
     * done before.
     *
     * @param int $numLevels
     * @return array
     */
    protected function getScaleFactors($numLevels): array
    {
        $result = [];
        foreach (range(0, $numLevels - 1) as $level) {
            $result[] = 2 ** $level;
        }
        return $result;
    }

    /**
     * Helper to get width and height of an image.
     *
     * @see \IiifServer\View\Helper\IiifManifest2::getWidthAndHeight()
     *
     * @param string $filepath This should be an image (no check here).
     * @return array Associative array of width and height of the image file.
     * Values are empty when the size is undetermined.
     */
    protected function getWidthAndHeight(string $filepath): array
    {
        if (file_exists($filepath)) {
            list($width, $height) = getimagesize($filepath);
            return [
                'width' => $width,
                'height' => $height,
            ];
        }

        return [
            'width' => null,
            'height' => null,
        ];
    }
}
