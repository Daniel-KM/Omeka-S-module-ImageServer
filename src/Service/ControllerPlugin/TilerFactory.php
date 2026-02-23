<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use Common\Stdlib\PsrMessage;
use ImageServer\ImageServer\Vips;
use ImageServer\Mvc\Controller\Plugin\Tiler;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ImageServer\ImageServer\ImageMagick;
use Omeka\Stdlib\Cli;

class TilerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $tileDir = $settings->get('imageserver_image_tile_dir');
        if (empty($tileDir)) {
            throw new \RuntimeException('The tile dir is not defined.'); // @translate
        }

        $cli = $services->get('Omeka\Cli');
        $config = $services->get('Config');
        $convertDir = $config['thumbnails']['thumbnailer_options']['imagemagick_dir'];
        $vipsDir = $settings->get('imageserver_vips_dir', '');
        $processor = $settings->get('imageserver_imager');

        $params = [];
        $params['tile_dir'] = $tileDir;
        $params['tile_type'] = $settings->get('imageserver_image_tile_type');
        $params['processor'] = $processor === 'Auto' ? '' : $processor;
        $params['convertPath'] = $this->getConvertPath($cli, $convertDir);
        $params['vipsPath'] = $this->getPath($cli, $vipsDir, Vips::VIPS_COMMAND);
        $logger = $services->get('Omeka\Logger');
        // Skip warnings when an external image server is configured, since
        // local image processing tools are not needed in that case.
        if (!$settings->get('iiifserver_media_api_url')) {
            if ($params['vipsPath'] === '') {
                $logger->warn(new PsrMessage(
                    'ImageServer: the command `{command}` was not found on the server. Install it, or set correct directory in configuration, or set another image processor.', // @translate
                    ['command' => Vips::VIPS_COMMAND]
                ));
            }
            if ($params['convertPath'] === '') {
                $logger->warn(new PsrMessage(
                    'ImageServer: the command `{command_1}` or `{command_2}` was not found on the server. Install it, or set correct directory in configuration, or set another image processor.', // @translate
                    ['command_1' => ImageMagick::MAGICK_COMMAND, 'command_2' => ImageMagick::CONVERT_COMMAND]
                ));
            }
        }
        $params['executeStrategy'] = $config['cli']['execute_strategy'];
        $params['basePath'] = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('AmazonS3');
        $params['hasAmazonS3'] = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        $module = $moduleManager->getModule('ArchiveRepertory');
        $params['hasArchiveRepertory'] = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        // If there is an overlap, the tile is usually transformed a second time
        // because OpenSeadragon asks for a multiple of the cell size.
        // So the overlap prevents simple redirect and so it is not recommended.
        // This param overrides the default of DeepZoom.
        // TODO Nevertheless, it is recommended with with tile size of 254.
        $params['tileOverlap'] = 0;

        return new Tiler(
            $params,
            $services->get('Omeka\EntityManager'),
            $services->get('ControllerPluginManager')
        );
    }

    /**
     * Get the path to "magick" (preferred) or "convert" (fallback).
     */
    protected function getConvertPath(Cli $cli, ?string $convertDir): string
    {
        $path = $this->getPath($cli, $convertDir, ImageMagick::MAGICK_COMMAND);
        if ($path !== '') {
            return $path;
        }
        return $this->getPath($cli, $convertDir, ImageMagick::CONVERT_COMMAND);
    }

    /**
     * Check and get the path of a command.
     */
    protected function getPath(Cli $cli, ?string $dir, string $command): string
    {
        return $dir
            ? (string) $cli->validateCommand($dir, $command)
            : (string) $cli->getCommandPath($command);
    }
}
