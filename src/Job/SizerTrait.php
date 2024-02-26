<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Api\Representation\MediaRepresentation;

trait SizerTrait
{
    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\ImageSize
     */
    protected $imageSize;

    /**
     * @var \Doctrine\ORM\EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $mediaRepository;

    /**
     * @var string
     */
    protected $filter;

    /**
     * @var array
     */
    protected $imageTypes;

    /**
     * @var int
     */
    protected $totalSucceed;

    /**
     * @var int
     */
    protected $totalFailed;

    /**
     * @var int
     */
    protected $totalSkipped;

    protected function prepareSizer(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->imageSize = $services->get('ControllerPluginManager')->get('imageSize');
        // The api cannot update value "data", so use entity manager.
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);

        $this->filter = $this->getArg('filter', 'all');
        if (!in_array($this->filter, ['all', 'sized', 'unsized'])) {
            $this->filter = 'all';
        }
        $this->imageTypes = array_keys($services->get('Config')['thumbnails']['types']);
        array_unshift($this->imageTypes, 'original');
    }

    protected function prepareSize(MediaRepresentation $media): void
    {
        if (strtok((string) $media->mediaType(), '/') !== 'image'
            // For ingester bulk_upload, wait that the process is finished, else
            // the thumbnails won't be available and the size of derivative will
            // be the fallback ones.
            || $media->ingester() === 'bulk_upload'
        ) {
            return;
        }

        // Keep possible data added by another module.
        $mediaData = $media->mediaData() ?: [];
        if ($this->filter === 'sized') {
            if (empty($mediaData['dimensions']['large']['width'])) {
                ++$this->totalSkipped;
                return;
            }
        } elseif ($this->filter === 'unsized') {
            if (!empty($mediaData['dimensions']['large']['width'])) {
                ++$this->totalSkipped;
                return;
            }
        }

        $this->logger->info(
            'Media #{media_id}: Sizing', // @translate
            ['media_id' => $media->id()]
        );

        /** @var \Omeka\Entity\Media $mediaEntity */
        $mediaEntity = $this->mediaRepository->find($media->id());

        // Reset dimensions to make the sizer working.
        // TODO In some cases, the original file is removed once the thumbnails are built.
        $mediaData['dimensions'] = [];
        $mediaEntity->setData($mediaData);

        $failedTypes = [];
        foreach ($this->imageTypes as $imageType) {
            $result = $this->imageSize->__invoke($media, $imageType);
            if (!array_filter($result)) {
                $failedTypes[] = $imageType;
            }
            $mediaData['dimensions'][$imageType] = $result;
        }
        if (count($failedTypes)) {
            $this->logger->err(
                'Media #{media_id}: Error getting dimensions for types "{types}".', // @translate
                ['media_id' => $mediaEntity->getId(), 'types' => implode('", "', $failedTypes)]
            );
            ++$this->totalFailed;
        }

        $mediaEntity->setData($mediaData);
        $this->entityManager->persist($mediaEntity);
        $this->entityManager->flush();
        unset($mediaEntity);

        ++$this->totalSucceed;
    }
}
