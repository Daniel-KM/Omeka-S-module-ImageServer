<?php declare(strict_types=1);

namespace ImageServerTest;

use CommonTest\AbstractHttpControllerTestCase;
use ImageServer\Job\BulkSizerAndTiler;
use Laminas\EventManager\Event;
use Omeka\Api\Request;
use Omeka\Api\Response;
use Omeka\Entity\Job;
use Omeka\Entity\Media;

/**
 * Tests for the sizing and tiling job launched when media are saved.
 *
 * Without auto-tiling, no job is launched for sized images. With auto-tiling, a
 * single job is launched by item, even when media are saved one by one, and
 * this job processes the media added during it.
 */
class SizeTileJobOnSaveTest extends AbstractHttpControllerTestCase
{
    use ImageServerTestTrait;

    protected const SIZED = [
        'dimensions' => [
            'original' => ['width' => 1000, 'height' => 800],
            'large' => ['width' => 800, 'height' => 640],
        ],
    ];

    /**
     * @var int
     */
    protected $itemId;

    /**
     * @var string|null
     */
    protected $tileMode;

    /**
     * @var int[]
     */
    protected $createdJobIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->itemId = $this->createItem()->id();
        $this->tileMode = $this->getServiceLocator()->get('Omeka\Settings')
            ->get('imageserver_tile_mode');
        $this->pendingDispatches(true);
    }

    public function tearDown(): void
    {
        $this->pendingDispatches(true);
        $this->getServiceLocator()->get('Omeka\Settings')
            ->set('imageserver_tile_mode', $this->tileMode);
        $connection = $this->getConnection();
        foreach ($this->createdJobIds as $jobId) {
            $connection->executeStatement('DELETE FROM `job` WHERE `id` = ?', [$jobId]);
        }
        $this->createdJobIds = [];
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testIsSizedImage(): void
    {
        $this->assertTrue($this->isSizedImage($this->insertMediaRow($this->itemId, 'html', 'html', 'text/html')));
        $this->assertFalse($this->isSizedImage($this->insertMediaRow($this->itemId, 'upload', 'file')));
        $this->assertTrue($this->isSizedImage($this->insertMediaRow($this->itemId, 'upload', 'file', 'image/jpeg', [
            'dimensions' => ['original' => ['width' => 1000, 'height' => 800]],
        ])));

        $mediaId = $this->insertMediaRow($this->itemId, 'upload', 'file', 'image/jpeg', [
            'dimensions' => ['original' => ['width' => 1000, 'height' => 800]],
        ]);
        $this->getConnection()->executeStatement('UPDATE `media` SET `has_thumbnails` = 1 WHERE `id` = ?', [$mediaId]);
        $this->assertFalse($this->isSizedImage($mediaId));

        $this->assertTrue($this->isSizedImage($this->insertSizedMedia()));
    }

    public function testNoJobForSizedMediaWithoutAutoTiling(): void
    {
        foreach (['external', 'manual'] as $tileMode) {
            $this->setTileMode($tileMode);
            $this->saveMedia($this->insertSizedMedia());
            $this->assertSame([], $this->pendingDispatches(true), $tileMode);
        }
    }

    public function testJobForUnsizedMediaWithoutAutoTiling(): void
    {
        $this->setTileMode('external');
        $this->saveMedia($this->insertMediaRow($this->itemId, 'upload', 'file'));
        $this->assertSame([\ImageServer\Job\BulkSizer::class], $this->pendingDispatches(true));
    }

    public function testNoJobForItemWithSizedMediaWithoutAutoTiling(): void
    {
        $this->setTileMode('external');
        $this->insertSizedMedia();
        $this->saveItem();
        $this->assertSame([], $this->pendingDispatches(true));

        $this->insertMediaRow($this->itemId, 'upload', 'file');
        $this->saveItem();
        $this->assertSame([\ImageServer\Job\BulkSizer::class], $this->pendingDispatches(true));
    }

    public function testJobWithAutoTiling(): void
    {
        $this->setTileMode('auto');
        $this->saveMedia($this->insertSizedMedia());
        $this->assertSame([BulkSizerAndTiler::class], $this->pendingDispatches(true));
    }

    public function testNoJobWhenJobIsRunningForItem(): void
    {
        $this->setTileMode('auto');
        $mediaId = $this->insertSizedMedia();

        foreach ([Job::STATUS_STARTING, Job::STATUS_IN_PROGRESS] as $status) {
            $jobId = $this->insertJob($status, ['id' => [$this->itemId]]);
            $this->saveMedia($mediaId);
            $this->assertSame([], $this->pendingDispatches(true), $status);
            $this->saveItem();
            $this->assertSame([], $this->pendingDispatches(true), $status);
            $this->setJobStatus($jobId, Job::STATUS_COMPLETED);
        }
    }

    public function testJobWhenRunningJobDoesNotCoverItem(): void
    {
        $this->setTileMode('auto');
        $mediaId = $this->insertSizedMedia();

        // Another item.
        $this->insertJob(Job::STATUS_IN_PROGRESS, ['id' => [$this->itemId + 1000000]]);
        // A bulk job with a query may have already processed the item.
        $this->insertJob(Job::STATUS_IN_PROGRESS, ['id' => [$this->itemId], 'item_set_id' => [1]]);
        // Finished job.
        $this->insertJob(Job::STATUS_COMPLETED, ['id' => [$this->itemId]]);

        $this->saveMedia($mediaId);
        $this->assertSame([BulkSizerAndTiler::class], $this->pendingDispatches(true));
    }

    public function testJobWhenRunningJobIsClosed(): void
    {
        $this->setTileMode('auto');
        $mediaId = $this->insertSizedMedia();
        $this->insertJob(Job::STATUS_IN_PROGRESS, ['id' => [$this->itemId]], ['closed' => true]);

        $this->saveMedia($mediaId);
        $this->assertSame([BulkSizerAndTiler::class], $this->pendingDispatches(true));
    }

    public function testJobWhenRunningJobIsStale(): void
    {
        $this->setTileMode('auto');
        $mediaId = $this->insertSizedMedia();
        $this->insertJob(Job::STATUS_IN_PROGRESS, ['id' => [$this->itemId]], [], new \DateTime('-2 hours'));

        $this->saveMedia($mediaId);
        $this->assertSame([BulkSizerAndTiler::class], $this->pendingDispatches(true));
    }

    public function testJobWhenMediaIsNotFlushed(): void
    {
        $this->setTileMode('auto');
        $mediaId = $this->insertSizedMedia();
        $this->insertJob(Job::STATUS_IN_PROGRESS, ['id' => [$this->itemId]]);

        $this->saveMedia($mediaId, ['flushEntityManager' => false]);
        $this->assertSame([BulkSizerAndTiler::class], $this->pendingDispatches(true));
    }

    public function testJobProcessesMediaAddedDuringIt(): void
    {
        $firstMediaId = $this->insertMediaRow($this->itemId, 'html', 'html', 'text/html');
        $job = $this->prepareJob($this->insertJob(Job::STATUS_IN_PROGRESS, ['id' => [$this->itemId]]));
        $this->setJobProperty($job, 'checkedMediaIds', [$firstMediaId => true]);

        $secondMediaId = $this->insertMediaRow($this->itemId, 'html', 'html', 'text/html');
        $this->callJobMethod($job, 'processNewMedia', [$this->itemId], ['size']);

        $checked = array_keys($this->getJobProperty($job, 'checkedMediaIds'));
        sort($checked);
        $this->assertSame([$firstMediaId, $secondMediaId], $checked);
    }

    public function testCloseJob(): void
    {
        $jobId = $this->insertJob(Job::STATUS_IN_PROGRESS, ['id' => [$this->itemId]]);
        $this->callJobMethod($this->prepareJob($jobId), 'closeJob');

        $args = json_decode((string) $this->getConnection()->fetchOne('SELECT `args` FROM `job` WHERE `id` = ?', [$jobId]), true);
        $this->assertTrue($args['closed']);
        $this->assertSame(['id' => [$this->itemId]], $args['query']);

        // Media saved later launch a new job.
        $this->setTileMode('auto');
        $this->saveMedia($this->insertSizedMedia());
        $this->assertSame([BulkSizerAndTiler::class], $this->pendingDispatches(true));
    }

    public function testRunJobChecksMediaOfItems(): void
    {
        $this->insertMediaRow($this->itemId, 'html', 'html', 'text/html');
        $args = ['tasks' => ['size'], 'query' => ['id' => [$this->itemId]], 'filter' => 'unsized'];
        $job = $this->runJob(BulkSizerAndTiler::class, $args);
        $this->createdJobIds[] = $job->getId();

        $this->assertNotSame(Job::STATUS_ERROR, $job->getStatus());
        $args = json_decode((string) $this->getConnection()->fetchOne('SELECT `args` FROM `job` WHERE `id` = ?', [$job->getId()]), true);
        $this->assertTrue($args['closed'] ?? false);
    }

    protected function module(): \ImageServer\Module
    {
        return $this->getServiceLocator()->get('ModuleManager')->getModule('ImageServer');
    }

    protected function setTileMode(string $tileMode): void
    {
        $this->getServiceLocator()->get('Omeka\Settings')->set('imageserver_tile_mode', $tileMode);
    }

    protected function insertSizedMedia(): int
    {
        return $this->insertMediaRow($this->itemId, 'upload', 'file', 'image/jpeg', self::SIZED);
    }

    protected function mediaEntity(int $mediaId): Media
    {
        $entityManager = $this->getEntityManager();
        $entityManager->clear();
        return $entityManager->find(Media::class, $mediaId);
    }

    protected function isSizedImage(int $mediaId): bool
    {
        $method = new \ReflectionMethod(\ImageServer\Module::class, 'isSizedImage');
        $method->setAccessible(true);
        return $method->invoke($this->module(), $this->mediaEntity($mediaId));
    }

    protected function saveMedia(int $mediaId, array $options = []): void
    {
        $request = new Request(Request::UPDATE, 'media');
        $request->setOption($options);
        $this->module()->handleAfterSaveMedia(new Event('api.update.post', null, [
            'request' => $request,
            'response' => new Response($this->mediaEntity($mediaId)),
        ]));
    }

    protected function saveItem(): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->clear();
        $this->module()->handleAfterSaveItem(new Event('api.update.post', null, [
            'request' => new Request(Request::UPDATE, 'items'),
            'response' => new Response($entityManager->find(\Omeka\Entity\Item::class, $this->itemId)),
        ]));
    }

    /**
     * Get the classes of the deferred jobs, and reset them if wanted.
     */
    protected function pendingDispatches(bool $reset = false): array
    {
        $deferred = $this->getServiceLocator()->get('Common\DeferredJobDispatch');
        $property = new \ReflectionProperty($deferred, 'pending');
        $property->setAccessible(true);
        $classes = array_values(array_filter(
            array_column($property->getValue($deferred), 'class'),
            fn ($class) => strpos($class, 'ImageServer\\') === 0
        ));
        if ($reset) {
            $property->setValue($deferred, []);
        }
        return $classes;
    }

    protected function insertJob(string $status, array $query, array $extraArgs = [], ?\DateTime $started = null): int
    {
        $connection = $this->getConnection();
        $connection->insert('job', [
            'owner_id' => null,
            'status' => $status,
            'class' => BulkSizerAndTiler::class,
            'args' => json_encode($extraArgs + [
                'tasks' => ['size', 'tile'],
                'query' => $query,
                'filter' => 'unsized',
            ]),
            'started' => ($started ?? new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        $jobId = (int) $connection->lastInsertId();
        $this->createdJobIds[] = $jobId;
        return $jobId;
    }

    protected function setJobStatus(int $jobId, string $status): void
    {
        $this->getConnection()->update('job', ['status' => $status], ['id' => $jobId]);
    }

    protected function prepareJob(int $jobId): BulkSizerAndTiler
    {
        $services = $this->getServiceLocator();
        $entityManager = $this->getEntityManager();
        $job = new BulkSizerAndTiler($entityManager->find(Job::class, $jobId), $services);
        $this->setJobProperty($job, 'logger', $services->get('Omeka\Logger'));
        $this->setJobProperty($job, 'entityManager', $entityManager);
        foreach (['totalImages', 'totalSucceed', 'totalFailed', 'totalSkipped'] as $name) {
            $this->setJobProperty($job, $name, 0);
        }
        $this->callJobMethod($job, 'prepareSizer');
        return $job;
    }

    protected function setJobProperty(BulkSizerAndTiler $job, string $name, $value): void
    {
        $property = new \ReflectionProperty($job, $name);
        $property->setAccessible(true);
        $property->setValue($job, $value);
    }

    protected function getJobProperty(BulkSizerAndTiler $job, string $name)
    {
        $property = new \ReflectionProperty($job, $name);
        $property->setAccessible(true);
        return $property->getValue($job);
    }

    protected function callJobMethod(BulkSizerAndTiler $job, string $name, ...$args)
    {
        $method = new \ReflectionMethod($job, $name);
        $method->setAccessible(true);
        return $method->invoke($job, ...$args);
    }
}
