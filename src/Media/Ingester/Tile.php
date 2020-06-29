<?php
namespace ImageServer\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\Downloader;
use Omeka\File\TempFile;
use Omeka\File\Uploader;
use Omeka\File\Validator;
use Omeka\Job\Dispatcher;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Zend\Form\Element\File;
use Zend\Form\Element\Url as UrlElement;
use Zend\Uri\Http as HttpUri;
use Zend\Validator\File\IsImage;
use Zend\View\Renderer\PhpRenderer;

class Tile implements IngesterInterface
{
    /**
     * @var Downloader
     */
    protected $downloader;

    /**
     * @var Validator
     */
    protected $validator;

    /**
     * @var Uploader
     */
    protected $uploader;

    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    /**
     * @param Downloader $downloader
     * @param Validator $validator
     * @param Uploader $uploader
     * @param Dispatcher $dispatcher
     */
    public function __construct(
        Downloader $downloader,
        Validator $validator,
        Uploader $uploader,
        Dispatcher $dispatcher
    ) {
        $this->downloader = $downloader;
        $this->validator = $validator;
        $this->uploader = $uploader;
        $this->dispatcher = $dispatcher;
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
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        if ($request->getValue('ingest_url')) {
            $this->ingestFromUrl($media, $request, $errorStore);
            return;
        }

        $fileData = $request->getValue('fileData');
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
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     * @see \Omeka\Media\Ingester\Url::ingest()
     */
    protected function ingestFromUrl(Media $media, Request $request, ErrorStore $errorStore)
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
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     * @see \Omeka\Media\Ingester\Upload::ingest()
     */
    protected function ingestFromFile(Media $media, Request $request, ErrorStore $errorStore)
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
            $media->setSource($fileData['file'][$index]['name']);
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
    ) {
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
        $args['storagePath'] = $this->getStoragePath('original', $media->getFilename());
        $args['storeOriginal'] = $storeOriginal;
        $args['type'] = $type;

        $job = $this->dispatcher->dispatch(\ImageServer\Job\Tiler::class, $args);

        $media->setData(['job' => $job->getId()]);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        $urlInput = new UrlElement('o:media[__index__][ingest_url]');
        $urlInput
            ->setOptions([
                'label' => 'Either a URL', // @translate
                'info' => 'A URL to the image.', // @translate
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
     * @see \Omeka\File\Validator
     *
     * @param TempFile $tempFile
     * @param ErrorStore|null $errorStore
     * @return bool
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
}
