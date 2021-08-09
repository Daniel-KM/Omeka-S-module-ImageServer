<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use ImageServer\Mvc\Controller\Plugin\ConvertToImage;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ConvertToImageFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new ConvertToImage(
            $plugins->get('imageServer')->__invoke()->getImager(),
            $plugins->get('imageSize')
        );
    }
}
