<?php declare(strict_types=1);

namespace ImageServer\Service\Controller;

use ImageServer\Controller\ImageController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ImageControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        return new ImageController(
            $basePath
        );
    }
}
