<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Entity\Media;
use Omeka\Job\AbstractJob;

class BulkTileClean extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\ORM\EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $mediaRepository;

    /**
     * @var int
     */
    protected $totalImages;

    /**
     * @var int
     */
    protected $totalProcessed;

    /**
     * @var int
     */
    protected $totalToProcess;

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

    public function perform(): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \ImageServer\Mvc\Controller\Plugin\TileRemover $tileRemover
         */
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $this->logger = $services->get('Omeka\Logger');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);
        $api = $services->get('Omeka\ApiManager');
        $tileRemover = $plugins->get('tileRemover');

        $query = $this->getArg('query', []);

        $response = $api->search('items', ['limit' => 0] + $query);
        $this->totalToProcess = $response->getTotalResults();
        if (empty($this->totalToProcess)) {
            $this->logger->warn(
                'No item selected. You may check your query.' // @translate
            );
            return;
        }

        $this->logger->info(
            'Starting bulk tile cleaning for {total} items.', // @translate
            ['total' => $this->totalToProcess]
        );


        $offset = 0;
        $this->totalImages = 0;
        $this->totalProcessed = 0;
        $this->totalSucceed = 0;
        $this->totalFailed = 0;
        $this->totalSkipped = 0;
        while (true) {
            /** @var \Omeka\Api\Representation\ItemRepresentation[] $items */
            $items = $api
                ->search('items', ['limit' => self::SQL_LIMIT, 'offset' => $offset] + $query)
                ->getContent();
            if (empty($items)) {
                break;
            }

            foreach ($items as $key => $item) {
                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job "Bulk Tile Cleaning" was stopped: {count}/{total} resources processed.', // @translate
                        ['count' => $offset + $key, 'total' => $this->totalToProcess]
                    );
                    break 2;
                }

                /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                foreach ($item->media() as $media) {
                    if (strtok((string) $media->mediaType(), '/') !== 'image'
                        // For ingester bulk_upload, wait that the process is
                        // finished, else the thumbnails won't be available and
                        // the size of derivative will be the fallback ones.
                        || $media->ingester() === 'bulk_upload'
                    ) {
                        unset($media);
                        continue;
                    }
                    ++$this->totalImages;

                    $mediaEntity = $this->mediaRepository->find($media->id());
                    $tileRemover($mediaEntity);
                    $this->removeMediaDataTile($mediaEntity);

                    unset($media);
                }
                unset($item);

                ++$this->totalProcessed;
            }

            $this->logger->info(
                '{count}/{total} items processed.', // @translate
                ['count' => $this->totalProcessed, 'total' => $this->totalToProcess]
            );

            // Flush one time each loop.
            $this->entityManager->flush();
            $this->entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of bulk tile cleaning: {count}/{total} items processed, {total_succeed} files sized, {total_failed} errors, {total_skipped} skipped on a total of {total_images} images.', // @translate
            [
                'count' => $this->totalProcessed,
                'total' => $this->totalToProcess,
                'total_succeed' => $this->totalSucceed,
                'total_failed' => $this->totalFailed,
                'total_skipped' => $this->totalSkipped,
                'total_images' => $this->totalImages,
            ]
        );
    }

    protected function removeMediaDataTile(Media $media): void
    {
        $mediaData = $media->getData();
        if (is_null($mediaData) || !array_key_exists('tile', $mediaData)) {
            return;
        }

        unset($mediaData['tile']);

        $media->setData($mediaData);
        $this->entityManager->persist($media);
        // No flush here.
    }
}
