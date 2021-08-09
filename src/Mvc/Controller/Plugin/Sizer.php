<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use IiifServer\Mvc\Controller\Plugin\ImageSize;
use ImageServer\Job\SizerTrait;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\MediaRepresentation;

class Sizer extends AbstractPlugin
{
    use SizerTrait;

    public function __construct(
        LoggerInterface $logger,
        ImageSize $imageSize,
        EntityManager $entityManager,
        EntityRepository $mediaRepository,
        array $imageTypes
    ) {
        $this->logger = $logger;
        $this->imageSize = $imageSize;
        $this->entityManager = $entityManager;
        $this->mediaRepository = $mediaRepository;
        $this->imageTypes = $imageTypes;
    }

    /**
     * Save sizes of an image.
     */
    public function __invoke(MediaRepresentation $media, array $params = []): ?array
    {
        $params = $params + [
            'filter' => 'all',
        ];
        $this->filter = $params['filter'];

        $this->prepareSize($media);

        // Refresh the data.
        $data = $this->mediaRepository->find($media->id())->getData();
        return $data['dimensions'] ?? null;
    }
}
