<?php declare(strict_types=1);

namespace ImageServer\Service\ViewHelper;

use ImageServer\View\Helper\TileMediaInfo;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Service factory for the TileMediaInfo view helper.
 */
class TileMediaInfoFactory implements FactoryInterface
{
    /**
     * Create and return the TileMediaInfo view helper
     *
     * @return TileMediaInfo
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new TileMediaInfo(
            $services->get('ControllerPluginManager')->get('tileMediaInfo')
        );
    }
}
