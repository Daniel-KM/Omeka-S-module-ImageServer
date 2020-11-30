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
    protected $setRendererTile;

    /**
     * @var string
     */
    protected $removeDestination;

    /**
     * @var string|bool
     */
    protected $updateRenderer;

    /**
     * @var string
     */
    protected $tileType;

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

    protected function prepareTiler(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->tiler = $services->get('ControllerPluginManager')->get('tiler');
        // The api cannot update value "renderer", so use entity manager.
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);

        $this->removeDestination = $this->getArg('remove_destination', 'skip') ?: 'skip';
        $this->updateRenderer = $this->getArg('update_renderer', false) ?: false;
        $this->tileType = $services->get('Omeka\Settings')->get('imageserver_image_tile_type', '');
    }

    protected function prepareTile(MediaRepresentation $media): void
    {
        if (!$media->hasOriginal()
            || strtok((string) $media->mediaType(), '/') !== 'image'
        ) {
            return;
        }

        $this->logger->info(new Message(
            'Media #%d: Start tiling (%s)', // @translate
            $media->id(),
            $this->tileType
        ));

        $result = $this->tiler->__invoke($media, $this->removeDestination);

        if ($result && !empty($result['result'])) {
            if (!empty($result['skipped'])) {
                $this->logger->info(new Message(
                    'Media #%d: Skipped because already tiled.', // @translate
                    $media->id()
                ));
                ++$this->totalSkipped;
            } else {
                $renderer = $media->renderer();
                if ($this->updateRenderer && $renderer !== $this->updateRenderer) {
                    /** @var \Omeka\Entity\Media $mediaEntity  */
                    $mediaEntity = $this->mediaRepository->find($media->id());
                    $mediaEntity->setRenderer($this->updateRenderer);
                    $this->entityManager->persist($mediaEntity);
                    $this->entityManager->flush();
                    unset($mediaEntity);
                    $this->logger->info(new Message(
                        'Media #%1$d: Renderer "%2$s" updated to "%3$s".', // @translate
                        $media->id(),
                        $renderer,
                        $this->updateRenderer
                    ));
                }
                $this->logger->info(new Message(
                    'Media #%d: End tiling', // @translate
                    $media->id()
                ));
                ++$this->totalSucceed;
            }
        } else {
            $this->logger->err(new Message(
                'Media #%d: Error during tiling', // @translate
                $media->id()
            ));
            ++$this->totalFailed;
        }
    }
}
