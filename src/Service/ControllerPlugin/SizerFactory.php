<?php declare(strict_types=1);

namespace ImageServer\Service\ControllerPlugin;

use ImageServer\Mvc\Controller\Plugin\Sizer;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SizerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // The api cannot update value "data", so use entity manager.
        $entityManager = $services->get('Omeka\EntityManager');
        $mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);

        $imageTypes = array_keys($services->get('Config')['thumbnails']['types']);
        array_unshift($imageTypes, 'original');

        return new Sizer(
            $services->get('Omeka\Logger'),
            $services->get('ControllerPluginManager')->get('imageSize'),
            $entityManager,
            $mediaRepository,
            $imageTypes
        );
    }
}
