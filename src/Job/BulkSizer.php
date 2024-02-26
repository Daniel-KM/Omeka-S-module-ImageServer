<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Job\AbstractJob;

class BulkSizer extends AbstractJob
{
    use SizerTrait;

    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

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

        $this->prepareSizer();

        $this->logger->info(
            'Starting bulk sizing for {total} items ({filter} media).', // @translate
            ['total' => $this->totalToProcess, 'filter' => $this->filter]
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
                        'The job "Bulk Sizer" was stopped: {count}/{total} resources processed.', // @translate
                        ['count' => $offset + $key, 'total' => $this->totalToProcess]
                    );
                    break 2;
                }

                /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                foreach ($item->media() as $media) {
                    if (strtok((string) $media->mediaType(), '/') === 'image'
                        // For ingester bulk_upload, wait that the process is
                        // finished, else the thumbnails won't be available and
                        // the size of derivative will be the fallback ones.
                        && $media->ingester() !== 'bulk_upload'
                    ) {
                        ++$this->totalImages;
                        $this->prepareSize($media);
                    }
                    unset($media);
                }
                unset($item);

                ++$this->totalProcessed;
            }

            $this->entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of bulk sizing: {count}/{total} items processed, {total_succeed} files sized, {total_failed} errors, {total_skipped} skipped on a total of {total_images} images.', // @translate
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
