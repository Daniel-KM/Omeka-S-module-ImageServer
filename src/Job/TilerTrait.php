<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Stdlib\Message;

trait TilerTrait
{
    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var \ImageServer\Mvc\Controller\Plugin\Tiler
     */
    protected $tiler;

    /**
     * @var \Doctrine\ORM\EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $mediaRepository;

    /**
     * @var bool
     */
    protected $removeDestination;

    protected function prepareTiler(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->tiler = $services->get('ControllerPluginManager')->get('tiler');
        // The api cannot update value "renderer", so use entity manager.
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);

        $this->removeDestination = (bool) $this->getArg('remove_destination', false);
    }

    protected function prepareTile(MediaRepresentation $media): void
    {
        if (!$media->hasOriginal()
            || strtok((string) $media->mediaType(), '/') !== 'image'
        ) {
            return;
        }

        $this->logger->info(new Message(
            'Starting tiling media #%d.', // @translate
            $media->id()
        ));

        $result = $this->tiler->__invoke($media, $this->removeDestination);

        if ($result && !empty($result['result'])) {
            if (!empty($result['skipped'])) {
                $this->logger->info(new Message(
                    'Media #%d skipped: already tiled.', // @translate
                    $media->id()
                ));
                ++$this->totalSkipped;
            } else {
                $renderer = $media->renderer();
                if ($renderer !== 'tile') {
                    /** @var \Omeka\Entity\Media $mediaEntity  */
                    $mediaEntity = $this->mediaRepository->find($media->id());
                    $mediaEntity->setRenderer('tile');
                    $this->entityManager->persist($mediaEntity);
                    $this->entityManager->flush();
                    unset($mediaEntity);
                    $this->logger->info(new Message(
                        'Renderer "%1$s" of media #%2$d updated to "tile".', // @translate
                        $renderer,
                        $media->id()
                    ));
                }
                $this->logger->info(new Message(
                    'End tiling media #%d.', // @translate
                    $media->id()
                ));
                ++$this->totalSucceed;
            }
        } else {
            $this->logger->err(new Message(
                'Error during tiling of media #%d.', // @translate
                $media->id()
            ));
            ++$this->totalFailed;
        }
    }
}
