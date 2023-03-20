<?php declare(strict_types=1);

namespace ImageServer\Service\File\Thumbnailer;

use ImageServer\File\Thumbnailer\Vips;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class VipsFactory implements FactoryInterface
{
    /**
     * Create the Vips thumbnailer service.
     *
     * @return Vips
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Vips(
            $services->get('Omeka\Cli'),
            $services->get('Omeka\File\TempFileFactory')
        );
    }
}
