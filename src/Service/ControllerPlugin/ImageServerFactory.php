<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use ImageServer\ImageServer\ImageServer;
use ImageServer\ImageServer\Vips;
use ImageServer\Mvc\Controller\Plugin\ImageServer as ImageServerPlugin;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Omeka\File\Thumbnailer\ImageMagick;
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
        $commandLineArgs['convertPath'] = $this->getPath($cli, $convertDir, ImageMagick::CONVERT_COMMAND);
        $commandLineArgs['vipsPath'] = $this->getPath($cli, $vipsDir, Vips::VIPS_COMMAND);
        $commandLineArgs['executeStrategy'] = $config['cli']['execute_strategy'];

        $imageServer = new ImageServer($tempFileFactory, $store, $commandLineArgs, $settings, $logger);

        return new ImageServerPlugin(
            $imageServer
        );
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
