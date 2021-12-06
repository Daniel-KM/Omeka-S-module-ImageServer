<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Entity\Media;

class TileRemover extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $tileDir;

    /**
     * @var \AmazonS3\File\Store\AwsS3
     */
    protected $amazonS3Store;

    /**
     * @param string $basePath
     * @param string $tileDir
     * @param \AmazonS3\File\Store\AwsS3 $amazonS3Store
     */
    public function __construct(string $basePath, string $tileDir, $amazonS3Store)
    {
        $this->basePath = $basePath;
        $this->tileDir = $tileDir;
        $this->amazonS3Store = $amazonS3Store;
    }

    /**
     * Remove all tiles for a media, for all formats by default.
     *
     * @param \Omeka\Entity\Media $media
     * @param array|string $formats
     */
    public function __invoke(Media $media, array $formats = [])
    {
        if (empty($this->tileDir)) {
            return;
        }

        if (empty($formats)) {
            $formats = [
                'deepzoom',
                'zoomify',
                'jpeg2000',
                'tiled_tiff',
            ];
        } elseif (!is_array($formats)) {
            $formats = [$formats];
        }

        // Remove all files and folders, whatever the format or the source.

        // The default storage interface doesn't manage directories directly.
        $storageId = $media->getStorageId();

        if ($this->amazonS3Store) {
            if (in_array('deepzoom', $formats)) {
                $filepath = $this->tileDir . '/' . $storageId . '.dzi';
                $this->amazonS3Store->delete($filepath);
                $filepath = $this->tileDir . '/' . $storageId . '.js';
                $this->amazonS3Store->delete($filepath);
                $filepath = $this->tileDir . '/' . $storageId . '_files';
                $this->amazonS3Store->deleteDir($filepath);
            }
            if (in_array('zoomify', $formats)) {
                $filepath = $this->tileDir . '/' . $storageId . '_zdata';
                $this->amazonS3Store->deleteDir($filepath);
            }
            if (in_array('jpeg2000', $formats)) {
                $filepath = $this->tileDir . '/' . $storageId . '.jp2';
                $this->amazonS3Store->delete($filepath);
            }
            if (in_array('tiled_tiff', $formats)) {
                $filepath = $this->tileDir . '/' . $storageId . '.tif';
                $this->amazonS3Store->delete($filepath);
            }
            return;
        }

        $tileDir = $this->basePath . '/' . $this->tileDir;

        if (in_array('deepzoom', $formats)) {
            $filepath = $tileDir . '/' . $storageId . '.dzi';
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            $filepath = $tileDir . '/' . $storageId . '.js';
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            $filepath = $tileDir . '/' . $storageId . '_files';
            if (file_exists($filepath) && is_dir($filepath)) {
                $this->rrmdir($filepath);
            }
        }
        if (in_array('zoomify', $formats)) {
            $filepath = $tileDir . '/' . $storageId . '_zdata';
            if (file_exists($filepath) && is_dir($filepath)) {
                $this->rrmdir($filepath);
            }
        }
        if (in_array('jpeg2000', $formats)) {
            $filepath = $tileDir . '/' . $storageId . '.jp2';
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
        if (in_array('tiled_tiff', $formats)) {
            $filepath = $tileDir . '/' . $storageId . '.tif';
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
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
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }

        return @rmdir($dir);
    }
}
