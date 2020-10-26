<?php
namespace ImageServer\Service\Media\Renderer;

use ImageServer\Media\Renderer\Tile;
use Omeka\Service\Exception\ConfigException;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;

class TileFactory implements FactoryInterface
{
    /**
     * Create the Tile renderer service.
     *
     * @return Tile
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            throw new ConfigException('The tile dir is not defined.'); // @translate
        }

        $module = $services->get('Omeka\ModuleManager')->getModule('AmazonS3');
        $hasAmazonS3 = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        return new Tile(
            $services->get('ControllerPluginManager')->get('tileInfo'),
            $tileDir,
            $hasAmazonS3
        );
    }
}
