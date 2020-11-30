<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use ImageServer\Mvc\Controller\Plugin\TileRemover;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TileRemoverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');

        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $tileDir = $settings->get('imageserver_image_tile_dir') ?: '';
        if (empty($tileDir)) {
            $logger = $services->get('Omeka\logger');
            $logger->err('The tile dir is not defined.'); // @translate
        }

        $module = $services->get('Omeka\ModuleManager')->getModule('AmazonS3');
        $hasAmazonS3 = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        $amazonS3Store = $hasAmazonS3
            ? $services->get(\AmazonS3\File\Store\AwsS3::class)
            : null;

        return new TileRemover(
            $basePath,
            $tileDir,
            $amazonS3Store
        );
    }
}
