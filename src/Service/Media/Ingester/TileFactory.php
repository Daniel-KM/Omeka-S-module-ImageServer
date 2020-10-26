<?php declare(strict_types=1);
namespace ImageServer\Service\Media\Ingester;

use ImageServer\Media\Ingester\Tile;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Omeka\Service\Exception\ConfigException;

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

        $module = $services->get('Omeka\ModuleManager')->getModule('AmazonS3');

        return new Tile(
            $services->get('Omeka\File\Downloader'),
            $services->get('Omeka\File\Uploader'),
            $settings->get('file_sideload_directory'),
            $settings->get('file_sideload_delete_file') === 'yes',
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('Omeka\File\Validator'),
            $services->get('Omeka\Job\Dispatcher'),
            $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files'),
            $module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE
        );
    }
}
