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

        $localConfig = $services->get('Config')['file_store']['local'];
        $viewHelpers = $services->get('ViewHelperManager');
        $basePath = $localConfig['base_path'] ?: (OMEKA_PATH . '/files');
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
            // A full url avoids some complexity when Omeka is not the root of
            // the server.
            $tileBaseDir = $basePath . DIRECTORY_SEPARATOR . $tileDir;
            if ($localConfig['base_uri']) {
                $tileBaseUrl = rtrim($localConfig['base_uri'], '/') . '/' . $tileDir;
            } else {
                $baseUrl = $settings->get('imageserver_base_url', '');
                if ($baseUrl) {
                    $tileBaseUrl = $baseUrl . 'files/' . $tileDir;
                } else {
                    $serverUrl = $viewHelpers->get('ServerUrl')->__invoke();
                    // Local store base path is different from url base path.
                    $basePath = $viewHelpers->get('BasePath');
                    $tileBaseUrl = $serverUrl . $basePath('files/' . $tileDir);
                }
            }
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
