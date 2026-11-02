<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use Common\Stdlib\DirectoryManager;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Stdlib\Cli;

/**
 * Render a page of a pdf as an image, like Cantaloupe.
 *
 * The identifier of a page is the identifier of the pdf and the number of the
 * page, separated with ";", for example "file.pdf;2". The page is rendered once
 * with vips at the resolution of the option of the module Iiif Server, and
 * cached as jpeg in the directory of the tiles, so the image server processes
 * it like any image. The directory is protected from a direct web access,
 * because the page may be reserved by the module Access, while the image server
 * checks the rights.
 */
class PdfPage extends AbstractPlugin
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
     * @var \Omeka\Stdlib\Cli
     */
    protected $cli;

    /**
     * @var string
     */
    protected $vipsPath;

    /**
     * @var int
     */
    protected $dpi;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Common\Stdlib\DirectoryManager
     */
    protected $directoryManager;

    public function __construct(
        string $basePath,
        string $tileDir,
        Cli $cli,
        string $vipsPath,
        int $dpi,
        Logger $logger,
        DirectoryManager $directoryManager
    ) {
        $this->basePath = $basePath;
        $this->tileDir = $tileDir;
        $this->cli = $cli;
        $this->vipsPath = $vipsPath;
        $this->dpi = $dpi ?: 150;
        $this->logger = $logger;
        $this->directoryManager = $directoryManager;
    }

    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Split an identifier into the identifier of the pdf and the page.
     *
     * @return array{0: string, 1: int} The page is 0 when there is no page.
     */
    public static function parseIdentifier(string $identifier): array
    {
        return preg_match('~^(.+);(\d+)$~', $identifier, $matches) && (int) $matches[2] > 0
            ? [$matches[1], (int) $matches[2]]
            : [$identifier, 0];
    }

    /**
     * Get the path of the rendered image of a page of a pdf.
     */
    public static function filepath(string $basePath, string $tileDir, string $storageId, int $page): string
    {
        return sprintf('%s/%s/pdf/%s/%d.jpg', $basePath, $tileDir, $storageId, $page);
    }

    /**
     * Get the vips command to render a page of a pdf as jpeg.
     *
     * The pages of vips start from 0.
     */
    public static function renderCommand(string $vipsPath, string $source, int $page, int $dpi, string $destination): string
    {
        return sprintf(
            '%s copy %s %s',
            $vipsPath,
            escapeshellarg(sprintf('%s[page=%d,dpi=%d]', $source, $page - 1, $dpi)),
            escapeshellarg($destination . '[Q=90,strip]')
        );
    }

    /**
     * Get the image of a page of a pdf, rendered if needed.
     *
     * @return array{filepath: string, width: int, height: int}|null Null when
     *   the media is not a pdf or when the page cannot be rendered.
     */
    public function render(AbstractResourceEntityRepresentation $media, int $page): ?array
    {
        if ($page < 1
            || $media->mediaType() !== 'application/pdf'
            || !$media->hasOriginal()
        ) {
            return null;
        }

        $filepath = self::filepath($this->basePath, $this->tileDir, (string) $media->storageId(), $page);
        if (!is_file($filepath) && !$this->renderPage($media, $page, $filepath)) {
            return null;
        }

        $size = @getimagesize($filepath);
        return $size
            ? ['filepath' => $filepath, 'width' => (int) $size[0], 'height' => (int) $size[1]]
            : null;
    }

    /**
     * Remove the rendered pages of a pdf.
     */
    public function remove(string $storageId): void
    {
        if ($storageId === '') {
            return;
        }
        $dirpath = sprintf('%s/%s/pdf/%s', $this->basePath, $this->tileDir, $storageId);
        foreach (glob($dirpath . '/*.jpg') ?: [] as $filepath) {
            @unlink($filepath);
        }
        @rmdir($dirpath);
    }

    protected function renderPage(AbstractResourceEntityRepresentation $media, int $page, string $filepath): bool
    {
        if (!$this->vipsPath) {
            $this->logger->err(
                'ImageServer: the command vips is required to render the pages of the pdf files.' // @translate
            );
            return false;
        }

        $source = $this->basePath . '/original/' . $media->filename();
        $dirpath = dirname($filepath);
        if (!$this->directoryManager->checkDestinationDir($this->basePath . '/' . $this->tileDir . '/pdf', true)
            || !is_readable($source)
            || !$this->directoryManager->checkDestinationDir($dirpath)
        ) {
            return false;
        }

        // Render in a temporary file, so a concurrent request never reads a
        // partial image.
        $tempPath = $dirpath . '/.' . $page . '.' . bin2hex(random_bytes(4)) . '.jpg';
        $this->cli->execute(self::renderCommand($this->vipsPath, $source, $page, $this->dpi, $tempPath));
        if (!is_file($tempPath) || !filesize($tempPath) || !@rename($tempPath, $filepath)) {
            @unlink($tempPath);
            $this->logger->warn(
                'ImageServer: unable to render the page {page} of the pdf media #{media_id}.', // @translate
                ['page' => $page, 'media_id' => $media->id()]
            );
            return false;
        }
        return true;
    }
}
