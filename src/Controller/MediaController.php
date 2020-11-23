<?php declare(strict_types=1);

/*
 * Copyright 2015-2020 Daniel Berthereau
 * Copyright 2016-2017 BibLibre
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace ImageServer\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Exception\BadRequestException;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\File\Store\StoreInterface;
use Omeka\Mvc\Exception\UnsupportedMediaTypeException;

/**
 * The Media controller class.
 *
 * @package ImageServer
 */
class MediaController extends AbstractActionController
{
    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    public function __construct($store, $basePath)
    {
        $this->store = $store;
        $this->basePath = $basePath;
    }

    /**
     * Redirect to the 'info' action, required by the feature "baseUriRedirect".
     *
     * @see self::infoAction()
     */
    public function indexAction()
    {
        $settings = $this->settings();
        $id = $this->params('id');
        $version = $this->params('version') ?: $settings->get('imageserver_info_default_version', '2');
        $url = $this->url()->fromRoute('mediaserver/info', [
            'id' => $id,
            'version' => $version,
            'prefix' => $this->params('prefix') ?: $settings->get('imageserver_identifier_prefix', ''),
        ], ['force_canonical' => true]);
        $this->getResponse()
            // TODO The iiif image api specification recommands 303, not 302.
            ->setStatusCode(\Laminas\Http\Response::STATUS_CODE_303)
            ->getHeaders()->addHeaderLine('Location', $url);
        return $this->iiifImageJsonLd('', $version);
    }

    /**
     * Returns an error 400 to requests that are invalid.
     */
    public function badAction()
    {
        $message = 'The Image server cannot fulfill the request: the arguments are incorrect.'; // @translate
        return $this->viewError(new BadRequestException($message), \Laminas\Http\Response::STATUS_CODE_400);
    }

    /**
     * Send "info.json" for the current file.
     *
     * The info is managed by the MediaControler because it indicates
     * capabilities of the IXIF server for the request of a file.
     */
    public function infoAction()
    {
        $resource = $this->fetchResource('media');
        if (!$resource) {
            return $this->jsonError(new NotFoundException, \Laminas\Http\Response::STATUS_CODE_404);
        }

        $version = $this->requestedVersion();

        /** @var \ImageServer\View\Helper\IiifInfo $iiifInfo */
        $iiifInfo = $this->viewHelpers()->get('iiifInfo');
        try {
            $info = $iiifInfo($resource, $version);
        } catch (\IiifServer\Iiif\Exception\RuntimeException $e) {
            return $this->jsonError($e, \Laminas\Http\Response::STATUS_CODE_400);
        }

        return $this->iiifImageJsonLd($info, $version);
    }

    /**
     * Returns the current file.
     */
    public function fetchAction()
    {
        $media = $this->fetchResource('media');
        if (!$media) {
            return $this->jsonError(new NotFoundException, \Laminas\Http\Response::STATUS_CODE_404);
        }

        $response = $this->getResponse();

        // Because there is no conversion currently, the format should be
        // checked.
        $format = strtolower((string) $this->params('format'));
        if (pathinfo($media->filename(), PATHINFO_EXTENSION) != $format) {
            return $this->viewError(new UnsupportedMediaTypeException(
                'The IXIF server encountered an unexpected error that prevented it from fulfilling the request: the requested format is not supported.', // @translate
                \Laminas\Http\Response::STATUS_CODE_500
            ));
        }

        // A check is added if the file is local: the source can be a local file
        // or an external one (Amazon S3…).
        switch (get_class($this->store)) {
            case \Omeka\File\Store\Local::class:
                $filepath = $this->basePath
                    . DIRECTORY_SEPARATOR . $this->getStoragePath('original', $media->filename());
                if (!file_exists($filepath) || filesize($filepath) == 0) {
                    return $this->viewError(new \IiifServer\Iiif\Exception\RuntimeException(
                        'The IXIF server encountered an unexpected error that prevented it from fulfilling the request: the resulting file is not found.', // @translate
                        \Laminas\Http\Response::STATUS_CODE_500
                    ));
                }
                break;
        }
        // TODO Check if the external url is not empty.

        // Header for CORS, required for access of IXIF.
        $response->getHeaders()
            ->addHeaderLine('access-control-allow-origin', '*')
            ->addHeaderLine('Content-Type', $media->mediaType());

        // TODO This is a local file (normal server): use 200.

        // Redirect (302/307) to the url of the file.
        $fileurl = $media->originalUrl();
        return $this->redirect()->toUrl($fileurl);
    }

    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix
     * @param string $name The file name, or basename if extension is passed
     * @param string|null $extension The file extension
     * @return string
     * @todo Refactorize.
     */
    protected function getStoragePath(string $prefix, string $name, $extension = null): string
    {
        return sprintf('%s/%s%s', $prefix, $name, $extension ? ".$extension" : null);
    }

    /**
     * @todo Factorize with \IiifServer\Controller\PresentationController::fetchResource()
     *
     * @param string $resourceType
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation|null
     */
    protected function fetchResource(string $resourceType): ?AbstractResourceEntityRepresentation
    {
        $id = $this->params('id');

        $useCleanIdentifier = $this->useCleanIdentifier();
        if ($useCleanIdentifier) {
            $getResourceFromIdentifier = $this->viewHelpers()->get('getResourceFromIdentifier');
            return $getResourceFromIdentifier($id, $resourceType);
        }

        try {
            return $this->api()->read($resourceType, $id)->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return null;
        }
    }

    protected function useCleanIdentifier(): bool
    {
        return $this->viewHelpers()->has('getResourcesFromIdentifiers')
            && $this->settings()->get('iiifserver_identifier_clean');
    }

    /**
     * Get the requested version from the headers.
     *
     * @todo Factorize with ImageController::requestedVersion()
     *
     * @return string|null
     */
    protected function requestedVersion(): string
    {
        // Check the version from the url first.
        $version = $this->params('version');
        if ($version === '2' || $version === '3') {
            return $version;
        }

        $accept = $this->getRequest()->getHeaders()->get('Accept')->toString();
        if (strpos($accept, 'iiif.io/api/image/3/context.json')) {
            return '3';
        }
        if (strpos($accept, 'iiif.io/api/image/2/context.json')) {
            return '2';
        }
        return $this->settings()->get('imageserver_info_default_version', '2') ?: '2';
    }

    protected function jsonError(\Exception $exception, $statusCode = 500): JsonModel
    {
        /* @var \Laminas\Http\Response $response */
        $this->getResponse()->setStatusCode($statusCode);
        return new JsonModel([
            'status' => 'error',
            'message' => $exception->getMessage(),
        ]);
    }

    protected function viewError(\Exception $exception, $statusCode = 500): ViewModel
    {
        /* @var \Laminas\Http\Response $response */
        $this->getResponse()->setStatusCode($statusCode);
        $view = new ViewModel;
        return $view
            ->setTemplate('image-server/media/error')
            ->setVariable('message', $exception->getMessage());
    }
}
