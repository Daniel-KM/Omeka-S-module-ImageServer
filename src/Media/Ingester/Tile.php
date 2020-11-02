<?php declare(strict_types=1);

namespace ImageServer\Media\Ingester;

use Laminas\Form\Element\File;
use Laminas\Form\Element\Url as UrlElement;
use Laminas\Uri\Http as HttpUri;
use Laminas\Validator\File\IsImage;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\Downloader;
use Omeka\File\TempFile;
use Omeka\File\TempFileFactory;
use Omeka\File\Uploader;
use Omeka\File\Validator;
use Omeka\Job\Dispatcher;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

class Tile implements IngesterInterface
{
    /**
     * @var Downloader
     */
    protected $downloader;

    /**
     * @var Uploader
     */
    protected $uploader;

    /**
     * @var string
     */
    protected $directory;

    /**
     * @var bool
     */
    protected $deleteFile;

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var Validator
     */
    protected $validator;

    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    /**
     * @var string
     */
    protected $basePathFiles;

    /**
     * @var bool
     */
    protected $hasAmazonS3;

    protected $dirMode = 0755;

    /**
     * @param Downloader $downloader
     * @param Uploader $uploader
     * @param string $directory
     * @param bool $deleteFile
     * @param TempFileFactory $tempFileFactory
     * @param Validator $validator
     * @param Dispatcher $dispatcher
     * @param string $basePathFiles
     * @param bool $hasAmazonS3
     */
    public function __construct(
        Downloader $downloader,
        Uploader $uploader,
        $directory,
        $deleteFile,
        TempFileFactory $tempFileFactory,
        Validator $validator,
        Dispatcher $dispatcher,
        $basePathFiles,
        $hasAmazonS3
    ) {
        // For url.
        $this->downloader = $downloader;
        // For file via form.
        $this->uploader = $uploader;
        // From module FileSideload.
        // Only work on the resolved real directory path.
        $this->directory = $directory ? realpath($directory) : false;
        $this->deleteFile = $deleteFile;
        $this->tempFileFactory = $tempFileFactory;
        // Process.
        $this->validator = $validator;
        $this->dispatcher = $dispatcher;
        $this->basePathFiles = $basePathFiles;
        $this->hasAmazonS3 = $hasAmazonS3;
    }

    public function getLabel()
    {
        return 'Tiler'; // @translate
    }

    public function getRenderer()
    {
        return 'tile';
    }

    /**
     * Create a tiled image from a uri or a uploaded file (like core Url and
     * Upload ingesters).
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        // Check if the url is a local path.
        $ingestUrl = $request->getValue('ingest_url');
        if ($ingestUrl) {
            if (strpos($ingestUrl, 'https://') === 0 || strpos($ingestUrl, 'http://') === 0) {
                $this->ingestFromUrl($media, $request, $errorStore);
                return;
            }
            $this->ingestFromLocalFile($media, $request, $errorStore);
            return;
        }

        $fileData = $request->getFileData();
        if (isset($fileData['tile'])) {
            $this->ingestFromFile($media, $request, $errorStore);
            return;
        }

        $errorStore->addError('error', 'No url and no file was submitted for tiling'); // @translate
    }

    /**
     * Accepts the following non-prefixed keys (like core Url):
     *
     * + ingest_url: (required) The URL to ingest. The idea is that some URLs
     *   contain sensitive data that should not be saved to the database, such
     *   as private keys. To preserve the URL, remove sensitive data from the
     *   URL and set it to o:source.
     * + store_original: (optional, default true) Whether to store an original
     *   file. This is helpful when you want the media to have thumbnails but do
     *   not need the original file.
     *
     * @see \Omeka\Media\Ingester\Url::ingest()
     *
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     */
    protected function ingestFromUrl(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        $uri = new HttpUri($data['ingest_url']);
        if (!($uri->isValid() && $uri->isAbsolute())) {
            $errorStore->addError('ingest_url', 'Invalid ingest URL'); // @translate
            return;
        }

        $tempFile = $this->downloader->download($uri, $errorStore);
        if (!$tempFile) {
            return;
        }

        $tempFile->setSourceName($uri->getPath());
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($uri);
        }

        if (!$this->validatorFileIsImage($tempFile, $errorStore)) {
            return;
        }

        $this->mediaIngestFinalize($media, $request, $errorStore, $tempFile, 'url');
    }

    /**
     * Accepts the following non-prefixed keys (mostly like module Sideload,
     * except the name of the key):
     *
     * + ingest_url: (required) The filename to ingest.
     * + store_original: (optional, default true) Store the original file?
     *
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     * @see \FileSideload\Media\Ingester\Sideload::ingest()
     */
    protected function ingestFromLocalFile(Media $media, Request $request, ErrorStore $errorStore): void
    {
        if (strlen($this->directory) < 2) {
            $errorStore->addError('ingest_url', 'The local file should be in a configured directory'); // @translate
            return;
        }

        $data = $request->getContent();

        // This check allows to use the same form for local and distant url.
        if (strpos($data['ingest_url'], 'file://') === 0) {
            $data['ingest_url'] = substr($data['ingest_url'], 7);
        }

        $isAbsolutePathInsideDir = $this->directory && strpos($data['ingest_url'], $this->directory) === 0;
        $filepath = $isAbsolutePathInsideDir
            ? $data['ingest_url']
            : $this->directory . DIRECTORY_SEPARATOR . $data['ingest_url'];
        $fileinfo = new \SplFileInfo($filepath);
        $realPath = $this->verifyFile($fileinfo);
        if (false === $realPath) {
            $errorStore->addError('ingest_url', sprintf(
                'Cannot sideload file "%s". File does not exist or does not have sufficient permissions', // @translate
                $filepath
            ));
            return;
        }

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($data['ingest_url']);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($data['ingest_url']);
        }

        // Copy the file to a temp path, so it is managed as a real temp file (#14).
        copy($realPath, $tempFile->getTempPath());

        if (!$this->validator->validate($tempFile, $errorStore)) {
            return;
        }

        if (!$this->validatorFileIsImage($tempFile, $errorStore)) {
            return;
        }

        $this->mediaIngestFinalize($media, $request, $errorStore, $tempFile, 'local');

        if ($this->deleteFile) {
            unlink($realPath);
        }
    }

    /**
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     * @see \Omeka\Media\Ingester\Upload::ingest()
     */
    protected function ingestFromFile(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        $fileData = $request->getFileData();

        if (!isset($data['tile_index'])) {
            $errorStore->addError('error', 'No tiling index was specified'); // @translate
            return;
        }

        $index = $data['tile_index'];
        if (!isset($fileData['tile'][$index])) {
            $errorStore->addError('error', 'No file uploaded for tiling for the specified index'); // @translate
            return;
        }

        $tempFile = $this->uploader->upload($fileData['tile'][$index], $errorStore);
        if (!$tempFile) {
            return;
        }

        $tempFile->setSourceName($fileData['tile'][$index]['name']);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($fileData['tile'][$index]['name']);
        }

        if (!$this->validator->validate($tempFile, $errorStore)) {
            return;
        }

        if (!$this->validatorFileIsImage($tempFile, $errorStore)) {
            return;
        }

        $this->mediaIngestFinalize($media, $request, $errorStore, $tempFile, 'file');
    }

    /**
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     * @param TempFile $tempFile
     * @param string $type
     * @see \Omeka\File\TempFile::mediaIngestFile()
     */
    protected function mediaIngestFinalize(
        Media $media,
        Request $request,
        ErrorStore $errorStore,
        TempFile $tempFile,
        $type
    ): void {
        $data = $request->getContent();

        $storeOriginal = (!isset($data['store_original']) || $data['store_original']);
        $storeThumbnails = true;
        $deleteTempFile = true;
        $hydrateFileMetadataOnStoreOriginalFalse = true;

        $media->setStorageId($tempFile->getStorageId());
        if ($storeOriginal || $hydrateFileMetadataOnStoreOriginalFalse) {
            $media->setExtension($tempFile->getExtension());
            $media->setMediaType($tempFile->getMediaType());
            $media->setSha256($tempFile->getSha256());
            $media->setSize($tempFile->getSize());
        }

        if ($this->hasAmazonS3) {
            // With Amazon, save the original file in a temp directory in order
            // to create tiles.
            $tiletmpContainerPath = $this->basePathFiles . '/tiletmp';
            if (!is_dir($tiletmpContainerPath)) {
                $result = mkdir($tiletmpContainerPath, $this->dirMode);
                if (!$result) {
                    $errorStore->addError('error', 'Unable to create the temp dir "tiletmp", required to create tiles on Amazon S3. Check rights in the local directory files/.'); // @translate
                    return;
                }
            }

            $mainPath = $tiletmpContainerPath . '/' . $tempFile->getStorageId() . '.' . $tempFile->getExtension();
            $result = copy($tempFile->getTempPath(), $mainPath);
            if (!$result) {
                $errorStore->addError('error', 'Unable to copy the file in the temp dir "tiletmp", required to create tiles on Amazon S3. Check rights in the local directory files/.'); // @translate
                return;
            }
        }

        // TODO Create thumbnails from the tiled image via the image server.
        if ($storeThumbnails) {
            $hasThumbnails = $tempFile->storeThumbnails();
            $media->setHasThumbnails($hasThumbnails);
        }

        // The original is temporary saved, and may be removed if the original
        // is not wanted.
        $media->setHasOriginal(true);

        $tempFile->storeOriginal();

        if (file_exists($tempFile->getTempPath()) && $deleteTempFile) {
            $tempFile->delete();
        }

        // Here, we have only a deleted temp file; there is no media id, maybe
        // no item id, and the storage id and path may be changed by another
        // process until the media is fully saved. So the job won’t know which
        // file to process.
        // So, the job id is saved temporarily in the data of the media and it
        // will be removed during the job process. The args are kept for info.

        $args = [];
        $args['storageId'] = $media->getStorageId();
        if ($this->hasAmazonS3) {
            $args['storagePath'] = $mainPath;
        } else {
            $args['storagePath'] = $this->getStoragePath('original', $media->getFilename());
        }

        $args['storeOriginal'] = $storeOriginal;
        $args['type'] = $type;

        // TODO Move this job to create tiles inside an event api.create.post to avoid an issue with renaming?
        $job = $this->dispatcher->dispatch(\ImageServer\Job\Tiler::class, $args);

        $media->setData(['job' => $job->getId()]);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        $urlInput = new UrlElement('o:media[__index__][ingest_url]');
        $urlInput
            ->setOptions([
                'label' => 'Either a URL', // @translate
                'info' => 'A URL to the image. Prefix it with "file://" for a local file managed via module Sideload', // @translate
            ])
            ->setAttributes([
                'id' => 'media-tile-ingest-url-__index__',
                'required' => false,
            ]);
        $fileInput = new File('tile[__index__]');
        $fileInput
            ->setOptions([
                'label' => 'Or a file', // @translate
                'info' => $view->uploadLimit(),
            ])
            ->setAttributes([
                'id' => 'media-tile-input-__index__',
                'required' => false,
            ]);
        return $view->formRow($urlInput)
            . $view->formRow($fileInput)
            . '<input type="hidden" name="o:media[__index__][tile_index]" value="__index__">';
    }

    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix
     * @param string $name The file name, or basename if extension is passed
     * @param null|string $extension The file extension
     * @return string
     * @todo Refactorize.
     */
    protected function getStoragePath($prefix, $name, $extension = null)
    {
        return sprintf('%s/%s%s', $prefix, $name, $extension ? ".$extension" : null);
    }

    /**
     * Validate if the file is an image.
     *
     * Note: Omeka S beta 5 doesn't use Zend file input validator chain any more.
     *
     * Pass the $errorStore object if an error should raise an API validation
     * error.
     *
     * @param TempFile $tempFile
     * @param ErrorStore|null $errorStore
     * @return bool
     * @see \Omeka\File\Validator
     */
    protected function validatorFileIsImage(TempFile $tempFile, ErrorStore $errorStore = null)
    {
        // $validatorChain = $fileInput->getValidatorChain();
        // $validatorChain->attachByName('FileIsImage', [], true);
        // $fileInput->setValidatorChain($validatorChain);

        $validator = new IsImage();
        $result = $validator->isValid([
            'tmp_name' => $tempFile->getTempPath(),
            'name' => $tempFile->getSourceName(),
            'type' => $tempFile->getMediaType(),
        ]);
        if (!$result) {
            if ($errorStore) {
                $errorStore->addError('tile', sprintf(
                    'Error validating "%s". The file to tile should be an image, not "%s".', // @translate
                    $tempFile->getSourceName(),
                    $tempFile->getMediaType()
                ));
            }
        }
        return $result;
    }

    /**
     * Verify the passed file.
     *
     * Working off the "real" base directory and "real" filepath: both must
     * exist and have sufficient permissions; the filepath must begin with the
     * base directory path to avoid problems with symlinks; the base directory
     * must be server-writable to delete the file; and the file must be a
     * readable regular file.
     *
     * @param \SplFileInfo $fileinfo
     * @return string|false The real file path or false if the file is invalid
     * @see \FileSideload\Media\Ingester\Sideload::verifyFile()
     */
    public function verifyFile(\SplFileInfo $fileinfo)
    {
        if (false === $this->directory) {
            return false;
        }
        $realPath = $fileinfo->getRealPath();
        if (false === $realPath) {
            return false;
        }
        if (0 !== strpos($realPath, $this->directory)) {
            return false;
        }
        if ($this->deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
            return false;
        }
        if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
            return false;
        }
        return $realPath;
    }
}
