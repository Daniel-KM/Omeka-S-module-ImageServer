<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use ImageServer\ImageServer\Vips;
use ImageServer\Mvc\Controller\Plugin\PdfPage;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class PdfPageFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $cli = $services->get('Omeka\Cli');
        $vipsDir = (string) $settings->get('imageserver_vips_dir', '');
        $vipsPath = $vipsDir
            ? (string) $cli->validateCommand($vipsDir, Vips::VIPS_COMMAND)
            : (string) $cli->getCommandPath(Vips::VIPS_COMMAND);

        return new PdfPage(
            $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files'),
            (string) $settings->get('imageserver_image_tile_dir', 'tile') ?: 'tile',
            $cli,
            $vipsPath,
            (int) $settings->get('iiifserver_manifest_pdf_pages_dpi', 150),
            $services->get('Omeka\Logger')
        );
    }
}
