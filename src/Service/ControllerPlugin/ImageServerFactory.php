<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use Common\Stdlib\PsrMessage;
use ImageServer\ImageServer\ImageMagick;
use ImageServer\ImageServer\ImageServer;
use ImageServer\ImageServer\Vips;
use ImageServer\Mvc\Controller\Plugin\ImageServer as ImageServerPlugin;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Omeka\Stdlib\Cli;
use Psr\Container\ContainerInterface;

class ImageServerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $store = $services->get('Omeka\File\Store');
        $logger = $services->get('Omeka\Logger');
        $settings = $services->get('Omeka\Settings');

        $cli = $services->get('Omeka\Cli');
        // Silent Cli for feature probes: Omeka\Cli logs every failed `command
        // -v` as an error, which is noise for optional binaries (magick, vips).
        // Use the silent variant for detection, keep Omeka\Cli for real command
        // execution.
        $probeCli = $services->get('ImageServer\Stdlib\CliNoLog');
        $config = $services->get('Config');
        $convertDir = $config['thumbnails']['thumbnailer_options']['imagemagick_dir'];
        $vipsDir = $settings->get('imageserver_vips_dir', '');

        $commandLineArgs = [];
        $commandLineArgs['cli'] = $cli;
        $commandLineArgs['convertPath'] = $this->getConvertPath($probeCli, $convertDir);
        $commandLineArgs['vipsPath'] = $this->getPath($probeCli, $vipsDir, Vips::VIPS_COMMAND);
        // Skip warnings when an external image server is configured, since
        // local image processing tools are not needed in that case.
        // In "Auto" mode, warn only if no processor is available at all.
        $processor = $settings->get('imageserver_imager', 'Auto');
        if (!$settings->get('iiifserver_media_api_url')) {
            $isAuto = $processor === 'Auto' || $processor === '';
            $hasPhpImager = extension_loaded('gd')
                || extension_loaded('imagick');
            if ($isAuto) {
                if ($commandLineArgs['vipsPath'] === ''
                    && $commandLineArgs['convertPath'] === ''
                    && !$hasPhpImager
                ) {
                    $logger->err(new PsrMessage(
                        'ImageServer: no image processor is available. Install the command `vips` or `magick`, or enable the PHP extension GD or Imagick.', // @translate
                    ));
                }
            } else {
                if ($processor === 'Vips'
                    && $commandLineArgs['vipsPath'] === ''
                ) {
                    $logger->err(new PsrMessage(
                        'ImageServer: the command `{command}` was not found on the server. Install it, set the correct directory in module configuration, or set the image processor to "Auto".', // @translate
                        ['command' => Vips::VIPS_COMMAND]
                    ));
                }
                if ($processor === 'ImageMagick'
                    && $commandLineArgs['convertPath'] === ''
                ) {
                    $logger->err(new PsrMessage(
                        'ImageServer: the command `{command_1}` or `{command_2}` was not found on the server. Install it, set the correct directory in module configuration, or set the image processor to "Auto".', // @translate
                        ['command_1' => ImageMagick::MAGICK_COMMAND, 'command_2' => ImageMagick::CONVERT_COMMAND]
                    ));
                }
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
