<?php declare(strict_types=1);

/*
 * Copyright 2015-2026 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace ImageServer\Mvc\Controller\Plugin;

/**
 * Tile server for native tiled formats (jpeg 2000 and pyramidal tiff).
 *
 * Unlike DeepZoom and Zoomify, which store individual tile files in a directory
 * tree, jpeg 2000 and pyramidal tiff store all pyramid levels in a single file.
 * The tile server validates that the request is tile-aligned and points the
 * transform pipeline to the tiled source file. Vips or ImageMagick then
 * extracts the requested region efficiently from the appropriate internal
 * pyramid level.
 */
class TileServerNativeTiled extends TileServer
{
    /**
     * {@inheritDoc}
     * @see \ImageServer\Mvc\Controller\Plugin\TileServer::__invoke()
     */
    public function __invoke(array $tileInfo, array $transform): ?array
    {
        if (empty($tileInfo)
            || !in_array($tileInfo['tile_type'], ['jpeg2000', 'tiled_tiff'])
        ) {
            return null;
        }

        // Quick check of supported transformation of tiles.
        // Same list as DeepZoom and Zoomify.
        if (
            !in_array($transform['region']['feature'], [
                'full',
                'square',
                'regionByPx',
                'regionByPct',
            ])
            || !in_array($transform['size']['feature'], [
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

        // Validate that the request is tile-aligned.
        // isOneBased = false: levels start at tile size (like Zoomify).
        $cellData = $this->getLevelAndPosition(
            $tileInfo,
            $transform['source'],
            $transform['region'],
            $transform['size'],
            false
        );
        if ($cellData === null) {
            return null;
        }

        // Build path to the single tiled file (JP2 or TIF).
        $filepath = $tileInfo['path_base']
            . DIRECTORY_SEPARATOR . $tileInfo['media_path'];
        $fileurl = $tileInfo['url_base']
            . '/' . $tileInfo['media_path'];

        // Determine media type from tile format.
        $mediaType = $tileInfo['format'] === 'jp2'
            ? 'image/jp2'
            : 'image/tiff';

        // Unlike DeepZoom/Zoomify where the source is a specific tile file, the
        // source here is the whole pyramidal file. The original transform
        // region and size are passed through: the transform pipeline extracts
        // the region from the pyramidal file and scales to the requested size.
        // Vips reads from the appropriate internal pyramid level automatically.
        return [
            'cell' => $cellData,
            'source' => [
                'fileurl' => $fileurl,
                'filepath' => $filepath,
                'derivativeType' => 'tile',
                'media_type' => $mediaType,
                'width' => $tileInfo['source']['width'],
                'height' => $tileInfo['source']['height'],
                'overlap' => 0,
            ],
            'region' => $transform['region'],
            'size' => $transform['size'],
        ];
    }
}
