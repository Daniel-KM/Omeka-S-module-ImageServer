<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use ImageServer\Mvc\Controller\Plugin\TileBuilder;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class TileBuilderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new TileBuilder(
            $services->get('ControllerPluginManager')->get('convertToImage')
        );
    }
}
