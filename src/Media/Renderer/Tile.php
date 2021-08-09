<?php declare(strict_types=1);

namespace ImageServer\Media\Renderer;

use ImageServer\Mvc\Controller\Plugin\TileMediaInfo;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\Renderer\RendererInterface;
use Omeka\Stdlib\Message;

class Tile implements RendererInterface
{
    /**
     * @var \ImageServer\Mvc\Controller\Plugin\TileMediaInfo
     */
    protected $tileMediaInfo;

    /**
     * @var string
     */
    protected $tileDir;

    /**
     * @var bool
     */
    protected $hasAmazonS3;

    /**
     * @param TileMediaInfo $tileMediaInfo
     * @param string $tileDir
     * @param bool $hasAmazonS3
     */
    public function __construct(TileMediaInfo $tileMediaInfo, $tileDir, $hasAmazonS3)
    {
        $this->tileMediaInfo = $tileMediaInfo;
        $this->tileDir = $tileDir;
        $this->hasAmazonS3 = $hasAmazonS3;
    }

    /**
     * Render a tiled image.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @param array $options These options are managed:
     *   - mode: set the rendering mode: "native" to the tiles type (default),
     *   "iiif" or "iiif-full". Native modes are quicker, but don’t benefit
     *   of the features and of the interoperability of the IIIF.
     *   - data: data are set from the tiles of the specified media, but they
     *   can be completed or overridden. See the OpenSeadragon docs (example:
     *   "debugMode" => true).
     * @return string
     */
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = [])
    {
        static $firstTile = true;

        $tileInfo = $this->tileMediaInfo->__invoke($media);
        if (empty($tileInfo) || empty($tileInfo['tile_type'])) {
            return new Message('No tile or no properties for media #%d.', // @translate
                $media->id());
        }

        $mode = !empty($options['mode']) ? $options['mode'] : 'native';

        $prefixId = 'osd';
        switch ($mode) {
            case 'iiif':
                $prefixId = 'iiif';
                $data = $this->getDataIiif($media, $view, $tileInfo);
                break;
            case 'iiif-full':
                $prefixId = 'iiif';
                $data = $this->getDataIiifFull($media, $view, $tileInfo);
                break;
            case 'native':
                switch ($tileInfo['tile_type']) {
                    case 'deepzoom':
                        $data = $this->getDataDeepzoom($media, $view, $tileInfo);
                        break;
                    case 'zoomify':
                        $data = $this->getDataZoomify($media, $view, $tileInfo);
                        break;
                    case 'iiif':
                        $prefixId = 'iiif';
                        $data = $this->getDataIiif($media, $view, $tileInfo);
                        break;
                    case 'iiif-full':
                        $prefixId = 'iiif';
                        $data = $this->getDataIiifFull($media, $view, $tileInfo);
                        break;
                }
                break;
        }

        if (empty($data)) {
            return new Message('Invalid data for media #%d.', // @translate
                $media->id());
        }

        $data['prefixUrl'] = $view->assetUrl('vendor/openseadragon/images/', 'Omeka', false, false);

        if (isset($options['data'])) {
            $data = array_merge($data, $options['data']);
        }

        $args = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $fullId = $prefixId . '-' . $media->id();
        if ($firstTile) {
            $firstTile = false;
            $view->headScript()
                ->appendFile($view->assetUrl('vendor/openseadragon/openseadragon.min.js', 'Omeka'), 'text/javascript', ['defer' => 'defer'])
                ->appendFile($view->assetUrl('js/openseadragon.js', 'ImageServer'), 'text/javascript', ['defer' => 'defer']);
            $js = 'var iiifViewerOpenSeaDragonArgs = {};';
        } else {
            $js = '';
        }
        $js .= "iiifViewerOpenSeaDragonArgs['$fullId'] = $args;";
        $view->headScript()
            ->appendScript($js);

        $noscript = 'OpenSeadragon is not available unless JavaScript is enabled.'; // @translate
        $image = <<<OUTPUT
<div class="openseadragon" id="$fullId" style="height: 800px;"></div>
<noscript>
    <p>{$noscript}</p>
    <img src="{$media->thumbnailUrl('large')}" height="800px" />
</noscript>
OUTPUT;

        return $image;
    }

    /**
     * Get rendering data from a dzi format.
     *
     * @param MediaRepresentation $media
     * @param PhpRenderer $view
     * @param array $tileInfo
     * @return array
     */
    protected function getDataDeepzoom(MediaRepresentation $media, PhpRenderer $view, array $tileInfo)
    {
        if ($this->hasAmazonS3) {
            // The url contains tile dir.
            $url = $tileInfo['url_base'] . '/' . $tileInfo['metadata_path'];
        } else {
            $url = $view->serverUrl()
                . $view->basePath('files' . '/' . $this->tileDir . '/' . $tileInfo['metadata_path']);
        }
        $args = [];
        $args['id'] = 'osd-' . $media->id();
        $args['prefixUrl'] = '';
        $args['tileSources'] = [$url];
        return $args;
    }

    /**
     * Get rendering data from a zoomify format.
     *
     * @param MediaRepresentation $media
     * @param PhpRenderer $view
     * @param array $tileInfo
     * @return array
     */
    protected function getDataZoomify(MediaRepresentation $media, PhpRenderer $view, array $tileInfo)
    {
        // NOTE OpenSeadragon doesn't support short syntax (url only) currently.
        if ($this->hasAmazonS3) {
            // The url contains tile dir.
            $url = $tileInfo['url_base'] . '/' . $tileInfo['media_path'] . '/';
        } else {
            $url = $view->serverUrl()
                . $view->basePath('files' . '/' . $this->tileDir . '/' . $tileInfo['media_path']) . '/';
        }
        $tileSource = [];
        $tileSource['type'] = 'zoomifytileservice';
        $tileSource['width'] = $tileInfo['source']['width'];
        $tileSource['height'] = $tileInfo['source']['height'];
        $tileSource['tilesUrl'] = $url;
        $args = [];
        $args['id'] = 'osd-' . $media->id();
        $args['prefixUrl'] = '';
        $args['tileSources'] = [$tileSource];
        return $args;
    }

    /**
     * Get rendering data for IIIF.
     *
     * @param MediaRepresentation $media
     * @param PhpRenderer $view
     * @param array $tileInfo
     * @return array|null
     */
    protected function getDataIiif(MediaRepresentation $media, PhpRenderer $view, array $tileInfo)
    {
        $args = [];
        $args['id'] = 'iiif-' . $media->id();
        $args['prefixUrl'] = '';
        $args['tileSources'] = $view->iiifMediaUrl($media, 'imageserver/info');
        return $args;
    }

    /**
     * Get rendering data for IIIF (full data).
     *
     * @param MediaRepresentation $media
     * @param PhpRenderer $view
     * @param array $tileInfo
     * @return array|null
     */
    protected function getDataIiifFull(MediaRepresentation $media, PhpRenderer $view, array $tileInfo)
    {
        $scaleFactors = $this->getScaleFactors(
            $tileInfo['source']['width'],
            $tileInfo['source']['height'],
            $tileInfo['size']
        );
        if (empty($scaleFactors)) {
            return;
        }

        $tile = [];
        $tile['width'] = $tileInfo['size'];
        $tile['scaleFactors'] = $scaleFactors;

        $data = [];
        $data['width'] = $tileInfo['source']['width'];
        $data['height'] = $tileInfo['source']['height'];
        $data['tiles'][] = $tile;

        $tileSource = [];
        $tileSource['@context'] = 'http://iiif.io/api/image/2/context.json';
        $tileSource['@id'] = $view->iiifMediaUrl($media, 'imageserver/info');
        $tileSource['protocol'] = 'http://iiif.io/api/image';
        $tileSource['profile'] = 'http://iiif.io/api/image/2/level2.json';
        $tileSource += $data;

        $args = [];
        $args['id'] = 'iiif-' . $media->id();
        $args['prefixUrl'] = '';
        $args['tileSources'] = [$tileSource];

        return $args;
    }

    /**
     * Get the scale factors of a tiled image for iiif.
     *
     * @param int $width
     * @param int $height
     * @param int $tileSize
     * @return array|null
     */
    protected function getScaleFactors($width, $height, $tileSize)
    {
        $scaleFactors = [];
        $maxSize = max($width, $height);
        $total = (int) ceil($maxSize / $tileSize);
        $factor = 1;
        while ($factor / 2 <= $total) {
            $scaleFactors[] = $factor;
            $factor = $factor * 2;
        }
        if (count($scaleFactors) <= 1) {
            return;
        }
        return $scaleFactors;
    }
}
