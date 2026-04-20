<?php declare(strict_types=1);
namespace ImageServer\Service\ViewHelper;

use ImageServer\View\Helper\ImageSize;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class ImageSizeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $pluginManager = $services->get('ControllerPluginManager');
        $plugin = $pluginManager->get('imageSize');
        return new ImageSize(
            $plugin
        );
    }
}
