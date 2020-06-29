<?php
namespace ImageServer\Service\Media\Ingester;

use ImageServer\Media\Ingester\Tile;
use Interop\Container\ContainerInterface;
use Omeka\Service\Exception\ConfigException;
use Zend\ServiceManager\Factory\FactoryInterface;

class TileFactory implements FactoryInterface
{
    /**
     * Create the Tile ingester service.
     *
     * @return Tile
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            throw new ConfigException('The tile dir is not defined.');
        }

        return new Tile(
            $services->get('Omeka\File\Downloader'),
            $services->get('Omeka\File\Uploader'),
            $settings->get('file_sideload_directory'),
            $settings->get('file_sideload_delete_file') === 'yes',
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('Omeka\File\Validator'),
            $services->get('Omeka\Job\Dispatcher')
        );
    }
}
