<?php declare(strict_types=1);

namespace ImageServer\Job;

use AmazonS3\File\Store\AwsS3;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Job\AbstractJob;
use Omeka\Job\Exception\InvalidArgumentException;
use Omeka\Job\Exception\RuntimeException;
use Omeka\Stdlib\Message;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Tiler extends AbstractJob
{
    /**
     * @var bool
     */
    protected $hasAmazonS3;

    /**
     * @var bool
     */
    protected $hasArchiveRepertory;

    /**
     * @var MediaRepresentation
     */
    protected $media;

    /**
     * @var string
     */
    protected $sourcePath;

    /**
     * @var string
     */
    protected $mediaStorageId;

    public function perform(): void
    {
        $this->fetchMedia();
        if (empty($this->media)) {
            throw new InvalidArgumentException('The media to tile cannot be identified.'); // @translate
        }

        // Get the storage path of the source to use for the tiling.
        $services = $this->getServiceLocator();
        $config = $services->get('Config');

        $module = $services->get('Omeka\ModuleManager')->getModule('AmazonS3');
        $this->hasAmazonS3 = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        $module = $services->get('Omeka\ModuleManager')->getModule('ArchiveRepertory');
        $this->hasArchiveRepertory = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // Use a local sub-folder to create the tiles when Amazon s3 is used.
        // TODO Update tilers in vendor/ to manage Amazon directly.
        $subfolder = $this->hasAmazonS3 ? '/tiletmp/' : '/original/';
        $this->sourcePath = $basePath . $subfolder . $this->media->filename();
        $this->mediaStorageId = $this->media->storageId();

        // When modules AmazonS3 && ArchiveRepertory are used together, check
        // the end of the renaming, if any.
        if ($this->hasAmazonS3 && $this->hasArchiveRepertory) {
            $this->checkRenaming();
        }

        if (!file_exists($this->sourcePath)) {
            $this->sourcePath = null;
            $this->endJob();
            throw new InvalidArgumentException('The media file to tile cannot be found.'); // @translate
        }

        $tiler = $services->get('ControllerPluginManager')->get('tiler');
        $result = $tiler($this->media);

        $this->endJob($result);
    }

    /**
     * Get media via the job id.
     */
    protected function fetchMedia(): void
    {
        // If no media, the event "api.create.post" may be not finished, so wait
        // 300 sec.
        // The issue can occur when there are multiple big files. In that case,
        // it is recommenced to use the bulk tiler.
        // TODO Integrate a queue in Omeka.
        $mediaId = $this->getMediaIdViaSql();

        if (empty($mediaId)) {
            sleep(10);
            $mediaId = $this->getMediaIdViaSql();
            if (empty($mediaId)) {
                sleep(20);
                $mediaId = $this->getMediaIdViaSql();
                if (empty($mediaId)) {
                    sleep(30);
                    $mediaId = $this->getMediaIdViaSql();
                    if (empty($mediaId)) {
                        sleep(30);
                        $mediaId = $this->getMediaIdViaSql();
                        if (empty($mediaId)) {
                            sleep(30);
                            $mediaId = $this->getMediaIdViaSql();
                            if (empty($mediaId)) {
                                sleep(30);
                                $mediaId = $this->getMediaIdViaSql();
                                if (empty($mediaId)) {
                                    sleep(30);
                                    $mediaId = $this->getMediaIdViaSql();
                                    if (empty($mediaId)) {
                                        sleep(30);
                                        $mediaId = $this->getMediaIdViaSql();
                                        if (empty($mediaId)) {
                                            sleep(30);
                                            $mediaId = $this->getMediaIdViaSql();
                                            if (empty($mediaId)) {
                                                sleep(30);
                                                $mediaId = $this->getMediaIdViaSql();
                                                if (empty($mediaId)) {
                                                    sleep(30);
                                                    $mediaId = $this->getMediaIdViaSql();
                                                    if (empty($mediaId)) {
                                                        return;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->media = $this->getServiceLocator()->get('Omeka\ApiManager')
            ->read('media', $mediaId)->getContent();
    }

    /**
     * Get the media of the current job via sql.
     *
     * @return int|null
     */
    protected function getMediaIdViaSql()
    {
        $jobId = (int) $this->job->getId();
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $sql = <<<SQL
SELECT `id`
FROM `media`
WHERE `data` = '{"job":$jobId}'
    OR `data` LIKE '%"job":$jobId,%'
    OR `data` LIKE '%"job":$jobId}' LIMIT 1;
SQL;
        return $connection->fetchColumn($sql);
    }

    /**
     * Check if the media still exists and clean data of the media.
     *
     * @param array|null $result
     */
    protected function endJob($result = null): void
    {
        $mediaId = $this->getMediaIdViaSql();

        // Clean the tiles if the media was removed between upload and the end
        // of the tiling.
        if (empty($mediaId)) {
            if (!empty($result['tile_file'])) {
                @unlink($result['tile_file']);
            }
            if (!empty($result['tile_dir'])) {
                $this->rrmdir($result['tile_dir']);
            }
            return;
        }

        if ($this->getArg('storeOriginal', true) && $this->sourcePath) {
            $storeOriginal = '';
        } else {
            $storeOriginal = ', `has_original` = 0';
            if (file_exists($this->sourcePath)) {
                @unlink($this->sourcePath);
            }
        }

        // Clean media data. They cannot be updated via api, so use a query.
        // TODO Use doctrine repository.
        $jobId = (int) $this->job->getId();
        $mediaId = (int) $mediaId;
        $sql = <<<SQL
UPDATE `media`
SET
`data` = CASE
    WHEN `data` = '{"job":$jobId}' THEN NULL
    WHEN `data` LIKE '%"job":$jobId,%' THEN REPLACE(`data`, '"job":$jobId,', '')
    WHEN `data` LIKE '%"job":$jobId}%' THEN REPLACE(`data`, '"job":$jobId}', '')
    ELSE `data`
    END
$storeOriginal
WHERE `id` = $mediaId;
SQL;
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $connection->executeStatement($sql);

        if ($this->hasAmazonS3) {
            if (file_exists($this->sourcePath)) {
                @unlink($this->sourcePath);
            }
            $this->moveToAmazonS3();
        }

        // If there is an issue in the tiling itself, the cleaning should be done.
        if ($result && empty($result['result'])) {
            throw new RuntimeException((string) new Message(
                'An error occurred during the tiling of media #%d.', // @translate
                $mediaId
            ));
        }
    }

    protected function checkRenaming(): void
    {
        $services = $this->getServiceLocator();
        $fileManager = $services->get('ArchiveRepertory\FileManager');
        /** @var \Omeka\Entity\Media $mediaRes */
        $mediaRes = $services->get('Omeka\ApiManager')
            ->read('media', $this->media->id(), [], ['responseContent' => 'resource'])->getContent();
        $originalStorageId = $this->getArg('storageId');
        $newStorageId = $fileManager->getStorageId($mediaRes);

        // The renaming ended.
        if ($originalStorageId === $newStorageId) {
            // Nothing to do.
            return;
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $extension = $mediaRes->getExtension();
        $fullExtension = strlen($extension) ? '.' . $extension : $extension;
        $originalFilename = $originalStorageId . $fullExtension;
        $newFilename = $newStorageId . $fullExtension;
        $originalSourcePath = $basePath . '/tiletmp/' . $originalFilename;
        $newSourcePath = $basePath . '/tiletmp/' . $newFilename;

        if (!file_exists($originalSourcePath) || file_exists($newSourcePath)) {
            return;
        }

        $dir = dirname($newSourcePath);
        if (!$this->createFolder($dir)) {
            // Exception is thrown below anyway.
            return;
        }

        // Create dir if needed.
        @rename($originalSourcePath, $newSourcePath);
        $this->sourcePath = $newSourcePath;
        $this->mediaStorageId = $newStorageId;
    }

    protected function moveToAmazonS3(): void
    {
        $services = $this->getServiceLocator();
        /** @var \AmazonS3\File\Store\AwsS3 $store */
        $store = $services->get(AwsS3::class);

        $config = $services->get('Config');
        $baseDir = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $skipLength = mb_strlen($baseDir) + 1;
        $tileDir = $baseDir . '/' . $services->get('Omeka\Settings')->get('imageserver_image_tile_dir');

        // The key is "ingesters", even if "tile" is no more an ingester.
        $suffixes = $config['archiverepertory']['ingesters']['tile']['extension'];
        $rmdir = [];
        $rmfile = [];
        foreach ($suffixes as $suffix) {
            $tileDirMedia = $tileDir . '/' . $this->mediaStorageId . $suffix;
            if (!file_exists($tileDirMedia)) {
                continue;
            }
            // AmazonS3 may throw exception.
            if (is_dir($tileDirMedia)) {
                $dir = new RecursiveDirectoryIterator($tileDirMedia);
                $files = new RecursiveIteratorIterator($dir);
                foreach ($files as $file) {
                    if ($file->getFileName() !== '..' && $file->getFileName() !== '.') {
                        $source = $file->getPath() . '/' . $file->getFileName();
                        $destination = mb_substr($source, $skipLength);
                        $store->put($source, $destination);
                    }
                }
                $rmdir[] = $tileDirMedia;
            } else {
                $store->put($tileDirMedia, mb_substr($tileDirMedia, $skipLength));
                $rmfile[] = $tileDirMedia;
            }
        }

        foreach ($rmdir as $dir) {
            $this->rrmdir($dir);
        }
        foreach ($rmfile as $file) {
            @unlink($file);
        }
    }

    /**
     * Checks and creates a local folder.
     *
     * @see \ArchiveRepertory\File\FileManager::createFolder()
     *
     * @param string $path Full path of the folder to create.
     * @return bool True if the path is created
     * @throws \Omeka\File\Exception\RuntimeException
     */
    protected function createFolder($path)
    {
        if ($path == '') {
            return true;
        }

        if (file_exists($path)) {
            if (is_dir($path)) {
                @chmod($path, 0775);
                if (is_writeable($path)) {
                    return true;
                }
                $msg = $this->translate('Error directory non writable: "%s".', $path);
                throw new RuntimeException('[ArchiveRepertory] ' . $msg);
            }
            $msg = $this->translate('Failed to create folder "%s": a file with the same name exists…', $path);
            throw new RuntimeException('[ArchiveRepertory] ' . $msg);
        }

        if (!mkdir($path, 0775, true)) {
            $msg = sprintf($this->translate('Error making directory: "%s".'), $path);
            throw new RuntimeException('[ArchiveRepertory] ' . $msg);
        }
        @chmod($path, 0775);

        return true;
    }

    /**
     * Removes directories recursively.
     *
     * @param string $dir Directory name.
     * @return bool
     */
    private function rrmdir($dir)
    {
        if (!file_exists($dir)
            || !is_dir($dir)
            || !is_readable($dir)
            || !is_writeable($dir)
        ) {
            return false;
        }

        $scandir = scandir($dir);
        if (!is_array($scandir)) {
            return false;
        }

        $files = array_diff($scandir, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }

        return @rmdir($dir);
    }

    protected function hasModule($module)
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($module);

        return $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }
}
