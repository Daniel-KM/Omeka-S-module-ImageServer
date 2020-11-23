<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use ImageServer\Job\SizerTrait;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\MediaRepresentation;

class Sizer extends AbstractPlugin
{
    use SizerTrait;

    /**
     * @param array $params
     * @param LoggerInterface $logger
     */
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
     *
     * @var MediaRepresentation $media
     * @var array $params
     * @return array|null Null on error, else data about sizing.
     */
    public function __invoke(MediaRepresentation $media, array $params = [])
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
