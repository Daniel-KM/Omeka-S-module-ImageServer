<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Job\AbstractJob;

class BulkTiler extends AbstractJob
{
    use TilerTrait;

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

    public function perform(): void
    {
        /** @var \Omeka\Api\Manager $api */
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');

        $query = $this->getArg('query', []);

        $response = $api->search('items', $query);
        $this->totalToProcess = $response->getTotalResults();
        if (empty($this->totalToProcess)) {
            $this->logger->warn(
                'No item selected. You may check your query.' // @translate
            );
            return;
        }

        $this->prepareTiler();

        $this->logger->info(
            'Starting bulk tiling for {total} items.', // @translate
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
                        'The job "Bulk Tiler" was stopped: {count}/{total} resources processed.', // @translate
                        ['count' => $offset + $key, 'total' => $this->totalToProcess]
                    );
                    break 2;
                }

                /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                foreach ($item->media() as $media) {
                    if ($media->hasOriginal()
                        && strtok((string) $media->mediaType(), '/') === 'image'
                        // For ingester bulk_upload, wait that the process is
                        // finished, else the thumbnails won't be available and
                        // the size of derivative will be the fallback ones.
                        && $media->ingester() !== 'bulk_upload'
                    ) {
                        ++$this->totalImages;
                        $this->prepareTile($media);
                    }
                    unset($media);
                }
                unset($item);

                ++$this->totalProcessed;
            }

            $this->logger->info(
                '{count}/{total} items processed.', // @translate
                ['count' => $this->totalProcessed, 'total' => $this->totalToProcess]
            );

            $this->entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of bulk tiling: {count}/{total} items processed, {total_succeed} files tiled, {total_failed} errors, {total_skipped} skipped on a total of {total_images} images.', // @translate
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
}
