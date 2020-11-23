<?php declare(strict_types=1);

namespace ImageServer\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class MediaSizerAndTiler extends AbstractJob
{
    use SizerTrait;
    use TilerTrait;

    public function perform(): void
    {
        /** @var \Omeka\Api\Manager $api */
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');

        $query = $this->getArg('query', []);
        $query['limit'] = 1;

        $response = $api->search('media', $query);
        $totalToProcess = $response->getTotalResults();
        if (empty($totalToProcess)) {
            $this->logger->warn(new Message(
                'No media selected. You may check your query.' // @translate
            ));
            return;
        }

        $tasks = array_intersect($this->getArg('tasks', ['size', 'tile']), ['size', 'tile']);
        if (empty($tasks)) {
            $this->logger->warn(new Message(
                'The job ended: no tasks (tile or size) defined.' // @translate
            ));
            return;
        }

        /** @var \Omeka\Api\Representation\MediaRepresentation $media */
        $media = $response->getContent();
        $media = reset($media);
        if (strtok((string) $media->mediaType(), '/') !== 'image') {
            return;
        }

        if (in_array('size', $tasks)) {
            $this->prepareSizer();
            $this->prepareSize($media);
        }

        if (in_array('tile', $tasks)) {
            $this->totalSucceed = 0;
            $this->prepareTiler();
            $this->prepareTile($media);
        }
    }
}
