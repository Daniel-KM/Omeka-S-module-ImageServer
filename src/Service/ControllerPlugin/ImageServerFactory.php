<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use ImageServer\ImageServer\ImageServer;
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
        $translator = $services->get('MvcTranslator');
        $logger = $services->get('Omeka\Logger');
        $settings = $services->get('Omeka\Settings');

        $cli = $services->get('Omeka\Cli');
        $config = $services->get('Config');
        $convertDir = $config['thumbnails']['thumbnailer_options']['imagemagick_dir'];

        $commandLineArgs = [];
        $commandLineArgs['cli'] = $cli;
        $commandLineArgs['convertPath'] = $this->getConvertPath($cli, $convertDir);
        $commandLineArgs['executeStrategy'] = $config['cli']['execute_strategy'];

        $imageServer = new ImageServer($tempFileFactory, $store, $commandLineArgs, $settings);
        $imageServer
            ->setLogger($logger)
            ->setTranslator($translator);

        return new ImageServerPlugin(
            $imageServer
        );
    }

    /**
     * Get the path to the ImageMagick "convert" command.
     *
     * @param Cli $cli
     * @param string $convertDir
     * @return string
     */
    protected function getConvertPath(Cli $cli, $convertDir)
    {
        $convertPath = $convertDir
            ? $cli->validateCommand($convertDir, ImageMagick::CONVERT_COMMAND)
            : $cli->getCommandPath(ImageMagick::CONVERT_COMMAND);
        return (string) $convertPath;
    }
}
