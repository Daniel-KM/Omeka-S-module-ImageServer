<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use ImageServer\Mvc\Controller\Plugin\TileInfo;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Omeka\File\Exception\ConfigException;

class TileInfoFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            throw new ConfigException('The tile dir is not defined.'); // @translate
        }

        $viewHelpers = $services->get('ViewHelperManager');
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $module = $services->get('Omeka\ModuleManager')->getModule('AmazonS3');
        $hasAmazonS3 = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        if ($hasAmazonS3) {
            // TODO Use config key [file_store][awss3][base_uri].
            $store = $services->get(\AmazonS3\File\Store\AwsS3::class);
            $tileBaseDir = $tileDir;
            $baseUrl = $store->getUri($tileDir);
            if (strpos($baseUrl, '?') === false) {
                $tileBaseUrl = $baseUrl;
                $tileBaseQuery = '';
            } else {
                list($tileBaseUrl, $tileBaseQuery) = explode('?', $baseUrl, 2);
            }
        } else {
            $tileBaseDir = $basePath . DIRECTORY_SEPARATOR . $tileDir;
            // A full url avoids some complexity when Omeka is not the root of
            // the server.
            $serverUrl = $viewHelpers->get('ServerUrl');
            // The local store base path is totally different from url base path.
            $basePath = $viewHelpers->get('BasePath');
            $tileBaseUrl = $serverUrl() . $basePath('files' . '/' . $tileDir);
            $tileBaseQuery = '';
            $store = null;
        }

        return new TileInfo(
            $tileBaseDir,
            $tileBaseUrl,
            $tileBaseQuery,
            $hasAmazonS3,
            $store,
            $services->get('ControllerPluginManager')->get('imageSize')
        );
    }
}
