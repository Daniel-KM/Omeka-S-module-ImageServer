<?php declare(strict_types=1);
namespace ImageServer\Service\ViewHelper;

use ImageServer\View\Helper\TileInfo;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Service factory for the TileInfo view helper.
 */
class TileInfoFactory implements FactoryInterface
{
    /**
     * Create and return the TileInfo view helper
     *
     * @return TileInfo
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new TileInfo(
            $services->get('ControllerPluginManager')->get('tileInfo')
        );
    }
}
