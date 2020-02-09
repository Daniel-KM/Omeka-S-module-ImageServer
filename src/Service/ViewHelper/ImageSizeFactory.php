<?php
namespace ImageServer\Service\ViewHelper;

use ImageServer\View\Helper\ImageSize;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ImageSizeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $pluginManager = $services->get('ControllerPluginManager');
        $plugin = $pluginManager->get('imageSize');
        return new ImageSize(
            $plugin
        );
    }
}
