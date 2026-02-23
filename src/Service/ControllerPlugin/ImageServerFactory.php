<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use Common\Stdlib\PsrMessage;
use ImageServer\ImageServer\ImageServer;
use ImageServer\ImageServer\Vips;
use ImageServer\Mvc\Controller\Plugin\ImageServer as ImageServerPlugin;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ImageServer\ImageServer\ImageMagick;
use Omeka\Stdlib\Cli;

class ImageServerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $store = $services->get('Omeka\File\Store');
        $logger = $services->get('Omeka\Logger');
        $settings = $services->get('Omeka\Settings');

        $cli = $services->get('Omeka\Cli');
        $config = $services->get('Config');
        $convertDir = $config['thumbnails']['thumbnailer_options']['imagemagick_dir'];
        $vipsDir = $settings->get('imageserver_vips_dir', '');

        $commandLineArgs = [];
        $commandLineArgs['cli'] = $cli;
        $commandLineArgs['convertPath'] = $this->getConvertPath($cli, $convertDir);
        $commandLineArgs['vipsPath'] = $this->getPath($cli, $vipsDir, Vips::VIPS_COMMAND);
        // Skip warnings when an external image server is configured, since
        // local image processing tools are not needed in that case.
        if (!$settings->get('iiifserver_media_api_url')) {
            if ($commandLineArgs['vipsPath'] === '') {
                $logger->warn(new PsrMessage(
                    'ImageServer: the command `{command}` was not found on the server. Install it, or set correct directory in configuration, or set another image processor.', // @translate
                    ['command' => Vips::VIPS_COMMAND]
                ));
            }
            if ($commandLineArgs['convertPath'] === '') {
                $logger->warn(new PsrMessage(
                    'ImageServer: the command `{command_1}` or `{command_2}` was not found on the server. Install it, or set correct directory in configuration, or set another image processor.', // @translate
                    ['command_1' => ImageMagick::MAGICK_COMMAND, 'command_2' => ImageMagick::CONVERT_COMMAND]
                ));
            }
        }
        $commandLineArgs['executeStrategy'] = $config['cli']['execute_strategy'];

        $imageServer = new ImageServer($tempFileFactory, $store, $commandLineArgs, $settings, $logger);

        return new ImageServerPlugin(
            $imageServer
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
