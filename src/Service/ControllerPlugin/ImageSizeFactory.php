<?php
namespace ImageServer\Service\ControllerPlugin;

use ImageServer\Mvc\Controller\Plugin\ImageSize;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ImageSizeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ImageSize(
            $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files'),
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('Omeka\ApiAdapterManager')
        );
    }
}
