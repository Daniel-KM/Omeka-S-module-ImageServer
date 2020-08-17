<?php
namespace ImageServer\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class BulkTiler extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    public function perform()
    {
        /**
         * @var array $config
         * @var \Omeka\Mvc\Controller\Plugin\Logger $logger
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Settings\Settings $settings
         * @var \Doctrine\ORM\EntityManager $entityManager
         */
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');
        $tiler = $services->get('ControllerPluginManager')->get('tiler');
        // The api cannot update value "renderer", so use entity manager.
        $entityManager = $services->get('Omeka\EntityManager');
        $repository = $entityManager->getRepository(\Omeka\Entity\Media::class);

        $query = $this->getArg('query', []);
        $removeDestination = $this->getArg('remove_destination', false);

        $response = $api->search('items', $query);
        $totalToProcess = $response->getTotalResults();
        if (empty($totalToProcess)) {
            $logger->warn(new Message(
                'No item selected. You may check your query.' // @translate
            ));
            return;
        }

        $logger->info(new Message(
            'Starting bulk tiling for %d items.', // @translate
            $totalToProcess
        ));

        $offset = 0;
        $totalImages = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        $totalSkipped = 0;
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
                    $logger->warn(new Message(
                        'The job "Bulk Tiler" was stopped: %1$d/%2$d resources processed.', // @translate
                        $offset + $key, $totalToProcess
                    ));
                    break 2;
                }

                /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                foreach ($item->media() as $media) {
                    if ($media->hasOriginal()
                        && strtok($media->mediaType(), '/') === 'image'
                    ) {
                        ++$totalImages;
                        $logger->info(new Message(
                            'Starting tiling media #%d.', // @translate
                            $media->id()
                        ));

                        $result = $tiler($media, $removeDestination);

                        if ($result && !empty($result['result'])) {
                            if (!empty($result['skipped'])) {
                                $logger->info(new Message(
                                    'Media #%d skipped: already tiled.', // @translate
                                    $media->id()
                                ));
                                ++$totalSkipped;
                            } else {
                                $renderer = $media->renderer();
                                if ($renderer !== 'tile') {
                                    // $api->update('media', $media->id(), ['renderer' => 'tile'], [], ['isPartial' => true]);
                                    /** @var \Omeka\Entity\Media $mediaEntity  */
                                    $mediaEntity = $repository->find($media->id());
                                    $mediaEntity->setRenderer('tile');
                                    $entityManager->persist($mediaEntity);
                                    $entityManager->flush();
                                    $logger->info(new Message(
                                        'Renderer "%1$s" of media #%2$d updated to "tile".', // @translate
                                        $renderer,
                                        $media->id()
                                    ));
                                }
                                $logger->info(new Message(
                                    'End tiling media #%d.', // @translate
                                    $media->id()
                                ));
                                ++$totalSucceed;
                            }
                        } else {
                            $logger->err(new Message(
                                'Error during tiling of media #%d.', // @translate
                                $media->id()
                            ));
                            ++$totalFailed;
                        }
                    }
                    unset($media);
                }
                unset($item);

                ++$totalProcessed;
            }

            $entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        $logger->info(new Message(
            'End of bulk tiling: %1$d/%2$d items processed, %3$d files tiled, %4$d errors, %5$d skipped on a total of %6$d images.', // @translate
            $totalProcessed,
            $totalToProcess,
            $totalSucceed,
            $totalFailed,
            $totalSkipped,
            $totalImages
        ));
    }
}
