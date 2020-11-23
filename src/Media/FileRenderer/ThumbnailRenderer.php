<?php declare(strict_types=1);

namespace ImageServer\Media\FileRenderer;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;

class ThumbnailRenderer extends \Omeka\Media\FileRenderer\ThumbnailRenderer
{
    public function render(
        PhpRenderer $view,
        MediaRepresentation $media,
        array $options = []
    ) {
        static $defaultThumbnailType;
        static $tileFallback;

        if (is_null($defaultThumbnailType)) {
            $setting = $view->plugin($view->status()->isSiteRequest() ? 'siteSetting' : 'setting');
            $defaultThumbnailType = (string) $setting('imageserver_default_thumbnail_type', 'large');
            $tileFallback = (string) $setting('imageserver_tile_fallback', 'tile_large');
        }

        $options['thumbnailType'] = $options['thumbnailType'] ?? $defaultThumbnailType;
        if ($options['thumbnailType'] === 'tile') {
            $result = $this->renderTile($view, $media, $options, $tileFallback);
            if ($result) {
                return $result;
            }
            $options['thumbnailType'] = $tileFallback;
        }

        return parent::render($view, $media, $options);
    }

    /**
     * Render an image with OpenSeadragon with tile if present, else with large.
     *
     * This rendering does not depend on iiif.
     *
     * @link https://openseadragon.github.io/docs
     *
     * @param \Laminas\View\Renderer\PhpRenderer $view
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     * @param array $options
     * @return string
     */
    protected function renderTile(
        PhpRenderer $view,
        MediaRepresentation $media,
        array $options,
        string $fallback
    ): string {
        static $tileInfo;
        static $headScript;
        static $prefixUrl;
        static $noscript;

        if (is_null($tileInfo)) {
            $tileInfo = $view->plugin('tileInfo');
        }

        $mediaTileInfo = $tileInfo($media);
        if (empty($mediaTileInfo)) {
            if (substr($fallback, 0, 5) !== 'tile_') {
                return '';
            }
            $fallbackType = substr($fallback, 5);
            $tileSources = [
                'type' => 'image',
                'url' => $fallbackType === 'original'
                    ? $media->originalUrl()
                    : $media->thumbnailUrl($fallbackType),
                'crossOriginPolicy' => 'Anonymous',
                'ajaxWithCredentials' => false,
            ];
        } else {
            $tileSources = $mediaTileInfo['url_base'] . '/' . $mediaTileInfo['metadata_path'];
        }
        $tileSources = json_encode($tileSources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Avoid to add the same script multiple times.
        if (is_null($headScript)) {
            $headScript = $view->headScript();
            $prefixUrl = $view->assetUrl('vendor/openseadragon/images/', 'Omeka', false, false);
            $noscript = $view->escapeHtml($view->translate('OpenSeadragon is not available unless JavaScript is enabled.')); // @translate
            $headScript->appendFile($view->assetUrl('vendor/openseadragon/openseadragon.min.js', 'Omeka'));
        }

        // @link https://openseadragon.github.io/docs/OpenSeadragon.html#.Options
        $script = <<<JS
var viewer = OpenSeadragon({
    id: 'osd-{$media->id()}',
    prefixUrl: '$prefixUrl',
    tileSources: $tileSources,
    minZoomImageRatio: 0.8,
    maxZoomPixelRatio: 12,
});
JS;
        // TODO Ideally, the script for OpenSeadragon should go inside header.
        // $headScript->appendScript($script);

        return <<<HTML
<div class="openseadragon" id="osd-{$media->id()}" style="height: 600px;"></div>
<script type="text/javascript">
$script
</script>
<noscript>
    <p>$noscript</p>
</noscript>
HTML;
    }
}
