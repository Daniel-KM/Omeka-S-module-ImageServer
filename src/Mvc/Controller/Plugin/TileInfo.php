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

use IiifServer\Mvc\Controller\Plugin\ImageSize;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\MediaRepresentation;

class TileInfo extends AbstractPlugin
{
    /**
     * Extension added to a folder name to store data and tiles for DeepZoom.
     *
     * @var string
     */
    const FOLDER_EXTENSION_DEEPZOOM = '_files';

    /**
     * Extension added to a folder name to store data and tiles for Zoomify.
     *
     * @var string
     */
    const FOLDER_EXTENSION_ZOOMIFY = '_zdata';

    /**
     * Base dir of tiles.
     *
     * @var string
     */
    protected $tileBaseDir;

    /**
     * Base url of tiles.
     *
     * @var string
     */
    protected $tileBaseUrl;

    /**
     * Query appended to the url of tiles (credentials).
     *
     * @var string
     */
    protected $tileBaseQuery;

    /**
     * @var bool
     */
    protected $hasAmazonS3;

    /**
     * @var \AmazonS3\File\Store\AwsS3|null
     */
    protected $store;

    /**
     * @var ImageSize
     */
    protected $imageSize;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @param string $tileBaseDir Full path prepended to a storage id. Is equal
     *   to $tileBaseUrl for remote storage.
     */
    public function __construct(
        ?string $tileBaseDir,
        ?string $tileBaseUrl,
        ?string $tileBaseQuery,
        bool $hasAmazonS3,
        ?\AmazonS3\File\Store\AwsS3 $store,
        ImageSize $imageSize,
        Logger $logger
    ) {
        $this->tileBaseDir = $tileBaseDir;
        $this->tileBaseUrl = $tileBaseUrl;
        $this->tileBaseQuery = $tileBaseQuery;
        $this->hasAmazonS3 = $hasAmazonS3;
        $this->store = $store;
        $this->imageSize = $imageSize;
        $this->logger = $logger;
    }

    /**
     * Retrieve info about the tiling of an image.
     *
     * @param MediaRepresentation $media
     * @param string|null $format If not set, get the first format it finds.
     * @return array|null
     */
    public function __invoke(MediaRepresentation $media, ?string $format = null): ?array
    {
        // Quick check for possible issue when used outside of the Image Server.
        $mediaType = $media->mediaType();
        if (substr($mediaType, 0, 6) !== 'image/') {
            return null;
        }
        return $this->getTilingData($media, $format);
    }

    /**
     * Check if an image is zoomed and return its main data.
     *
     * Path to the storage of tiles:
     * - For DeepZoom: files/tile/storagehash_files with metadata "storagehash.js"
     * or "storagehash.dzi" and no subdir.
     * - For Zoomify: files/zoom_tiles/storagehash_zdata and, inside it,
     * metadata "ImageProperties.xml" and subdirs "TileGroup{x}".
     * - For Jpeg2000 or tiled tiff, only the file.
     *
     * This implementation is compatible with ArchiveRepertory (use of a
     * basename that may be a partial path) and possible alternate adapters.
     *
     * @param MediaRepresentation $media
     * @param string|null $format
     * @return array|null
     */
    protected function getTilingData(MediaRepresentation $media, ?string $format = null): ?array
    {
        $basename = $media->storageId();

        if (empty($format) || $format === 'deepzoom') {
            $basepath = $this->tileBaseDir . DIRECTORY_SEPARATOR . $basename . '.dzi';
            if ($this->fileExists($basepath)) {
                $tilingData = $this->getTilingDataDeepzoomDzi($basepath);
                $tilingData['media_path'] = $basename . self::FOLDER_EXTENSION_DEEPZOOM;
                $tilingData['metadata_path'] = $basename . '.dzi';
                return $tilingData;
            }

            $basepath = $this->tileBaseDir . DIRECTORY_SEPARATOR . $basename . '.js';
            if ($this->fileExists($basepath)) {
                $tilingData = $this->getTilingDataDeepzoomJsonp($basepath);
                $tilingData['media_path'] = $basename . self::FOLDER_EXTENSION_DEEPZOOM;
                $tilingData['metadata_path'] = $basename . '.js';
                return $tilingData;
            }

            if ($format === 'deepzoom') {
                return null;
            }
        }

        if (empty($format) || $format === 'zoomify') {
            $basepath = $this->tileBaseDir
                . DIRECTORY_SEPARATOR . $basename . self::FOLDER_EXTENSION_ZOOMIFY
                . DIRECTORY_SEPARATOR . 'ImageProperties.xml';
            if ($this->fileExists($basepath)) {
                $tilingData = $this->getTilingDataZoomify($basepath);
                $tilingData['media_path'] = $basename . self::FOLDER_EXTENSION_ZOOMIFY;
                $tilingData['metadata_path'] = $basename . self::FOLDER_EXTENSION_ZOOMIFY
                    . DIRECTORY_SEPARATOR . 'ImageProperties.xml';
                return $tilingData;
            }

            if ($format === 'zoomify') {
                return null;
            }
        }

        if (empty($format) || $format === 'jpeg2000') {
            $basepath = $this->tileBaseDir . DIRECTORY_SEPARATOR . $basename . '.jp2';
            if ($this->fileExists($basepath)) {
                $tilingData = $this->getTilingDataMedia($basepath, 'jpeg2000', $media);
                $tilingData['media_path'] = $basename . '.jp2';
                $tilingData['metadata_path'] = null;
                return $tilingData;
            }
        }

        if (empty($format) || $format === 'tiled_tiff') {
            $basepath = $this->tileBaseDir . DIRECTORY_SEPARATOR . $basename . '.tif';
            if ($this->fileExists($basepath)) {
                $tilingData = $this->getTilingDataMedia($basepath, 'tiled_tiff', $media);
                $tilingData['media_path'] = $basename . '.tif';
                $tilingData['metadata_path'] = null;
                return $tilingData;
            }
        }

        return null;
    }

    protected function fileExists($path): bool
    {
        return $this->hasAmazonS3
            ? $this->store->hasFile($path)
            : file_exists($path);
    }

    /**
     * Get rendering data from a dzi format.
     *
     * @param string $path
     * @return array|null
     */
    protected function getTilingDataDeepzoomDzi($path): ?array
    {
        if ($this->hasAmazonS3) {
            $path = $this->store->getUri($path);
        }
        if (!function_exists('simplexml_load_file')) {
            $this->logger->err('Php extension php-xml is not installed'); // @translate
            return null;
        }
        $xml = @simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_PARSEHUGE);
        if (!$xml) {
            return null;
        }
        $data = json_encode($xml);
        $data = json_decode($data, true);

        $tilingData = [];
        $tilingData['tile_type'] = 'deepzoom';
        $tilingData['metadata_path'] = $path;
        $tilingData['media_path'] = '';
        $tilingData['url_base'] = $this->tileBaseUrl;
        $tilingData['path_base'] = $this->hasAmazonS3 ? $this->tileBaseUrl : $this->tileBaseDir;
        $tilingData['url_query'] = $this->tileBaseQuery;
        $tilingData['size'] = (int) $data['@attributes']['TileSize'];
        $tilingData['overlap'] = (int) $data['@attributes']['Overlap'];
        $tilingData['total'] = null;
        $tilingData['source']['width'] = (int) $data['Size']['@attributes']['Width'];
        $tilingData['source']['height'] = (int) $data['Size']['@attributes']['Height'];
        $tilingData['format'] = $data['@attributes']['Format'];
        return $tilingData;
    }

    /**
     * Get rendering data from a jsonp format.
     *
     * @param string $path
     * @return array|null
     */
    protected function getTilingDataDeepzoomJsonp($path): ?array
    {
        if ($this->hasAmazonS3) {
            $path = $this->store->getUri($path);
        }
        $data = @file_get_contents($path);
        if (!$data) {
            return null;
        }
        // Keep only the json object.
        $data = substr($data, strpos($data, '{'), strrpos($data, '}') - strpos($data, '{') + 1);
        $data = json_decode($data, true);
        $data = $data['Image'];

        $tilingData = [];
        $tilingData['tile_type'] = 'deepzoom';
        $tilingData['metadata_path'] = '';
        $tilingData['media_path'] = '';
        $tilingData['url_base'] = $this->tileBaseUrl;
        $tilingData['path_base'] = $this->hasAmazonS3 ? $this->tileBaseUrl : $this->tileBaseDir;
        $tilingData['url_query'] = $this->tileBaseQuery;
        $tilingData['size'] = (int) $data['TileSize'];
        $tilingData['overlap'] = (int) $data['Overlap'];
        $tilingData['total'] = null;
        $tilingData['source']['width'] = (int) $data['Size']['Width'];
        $tilingData['source']['height'] = (int) $data['Size']['Height'];
        $tilingData['format'] = $data['Format'];
        return $tilingData;
    }

    /**
     * Get rendering data from a zoomify format.
     *
     * @param string $path
     * @return array|null
     */
    protected function getTilingDataZoomify($path): ?array
    {
        if ($this->hasAmazonS3) {
            $path = $this->store->getUri($path);
        }
        if (!function_exists('simplexml_load_file')) {
            $this->logger->err('Php extension php-xml is not installed'); // @translate
            return null;
        }
        $xml = @simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_PARSEHUGE);
        if (!$xml) {
            return null;
        }
        $properties = $xml->attributes();
        // reset($properties) is deprecated, so use object properties directly.

        $tilingData = [];
        $tilingData['tile_type'] = 'zoomify';
        $tilingData['metadata_path'] = '';
        $tilingData['media_path'] = '';
        $tilingData['url_base'] = $this->tileBaseUrl;
        $tilingData['path_base'] = $this->hasAmazonS3 ? $this->tileBaseUrl : $this->tileBaseDir;
        $tilingData['url_query'] = $this->tileBaseQuery;
        $tilingData['size'] = (int) @$properties->TILESIZE;
        $tilingData['overlap'] = 0;
        $tilingData['total'] = (int) @$properties->NUMTILES;
        $tilingData['source']['width'] = (int) @$properties->WIDTH;
        $tilingData['source']['height'] = (int) @$properties->HEIGHT;
        $tilingData['format'] = (string) @$properties->FORMAT ?: 'jpg';
        return $tilingData;
    }

    /**
     * Get rendering data from a media.
     *
     * @param string $path
     * @param string $tileType
     * @param MediaRepresentation $media
     * @return array|null
     */
    protected function getTilingDataMedia($path, $tileType, MediaRepresentation $media): ?array
    {
        $imageSize = $this->imageSize->__invoke($media);
        if (!$imageSize['width'] || !$imageSize['height']) {
            return null;
        }

        $tileTypes = [
            'jpeg2000' => 'jp2',
            'tiled_tiff' => 'tif',
        ];

        $tilingData = [];
        $tilingData['tile_type'] = $tileType;
        $tilingData['metadata_path'] = '';
        $tilingData['media_path'] = '';
        $tilingData['url_base'] = $this->tileBaseUrl;
        $tilingData['path_base'] = $this->tileBaseDir;
        $tilingData['url_query'] = $this->tileBaseQuery;
        $tilingData['size'] = 256;
        $tilingData['overlap'] = 0;
        $tilingData['total'] = null;
        $tilingData['source']['width'] = (int) $imageSize['width'];
        $tilingData['source']['height'] = (int) $imageSize['height'];
        $tilingData['format'] = $tileTypes[$tileType];
        return $tilingData;
    }
}
