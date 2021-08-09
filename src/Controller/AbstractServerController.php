<?php declare(strict_types=1);

/*
 * Copyright 2015-2021 Daniel Berthereau
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

use ImageServer\ImageServer\ImageServer;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Exception\BadRequestException;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * The server controller class.
 *
 * @package ImageServer
 */
class AbstractServerController extends AbstractActionController
{
    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $version;

    /**
     * @var string
     */
    protected $routeInfo;

    /**
     * Redirect to the 'info' action, required by the feature "baseUriRedirect".
     *
     * @see self::infoAction()
     */
    public function indexAction()
    {
        $settings = $this->settings();
        $id = $this->params('id');
        $this->requestedVersion();
        $url = $this->url()->fromRoute($this->routeInfo, [
            'version' => $this->version,
            'prefix' => $this->params('prefix') ?: $settings->get('imageserver_identifier_prefix', ''),
            'id' => $id,
        ], ['force_canonical' => true]);
        $this->getResponse()
            // The iiif image api specification recommands 303, not 302.
            ->setStatusCode(\Laminas\Http\Response::STATUS_CODE_303)
            ->getHeaders()->addHeaderLine('Location', $url);
        // The output automatically adds the version in headers.
        return $this->iiifImageJsonLd('', $this->version);
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
     * The info is managed by the ImageControler because it indicates
     * capabilities of the Image server for the request of a file.
     */
    public function infoAction()
    {
        $resource = $this->fetchResource('media');
        if (!$resource) {
            return $this->jsonError(new NotFoundException, \Laminas\Http\Response::STATUS_CODE_404);
        }

        $this->requestedVersion();

        /** @var \ImageServer\View\Helper\IiifInfo $iiifInfo */
        $iiifInfo = $this->viewHelpers()->get('iiifInfo');
        try {
            $info = $iiifInfo($resource, $this->version);
        } catch (\IiifServer\Iiif\Exception\RuntimeException $e) {
            return $this->jsonError($e, \Laminas\Http\Response::STATUS_CODE_400);
        }

        return $this->iiifImageJsonLd($info, $this->version);
    }

    public function placeholderAction()
    {
        $response = $this->getResponse();

        // TODO Manage other placeholders or use a setting.
        $placeholder = 'img/thumbnails/placeholder-image.png';
        $extension = pathinfo($placeholder, PATHINFO_EXTENSION);

        // Header for CORS, required for access.
        $response->getHeaders()
            ->addHeaderLine('access-control-allow-origin', '*')
            ->addHeaderLine('Content-Type', $this->mediaTypes[$extension]);

        // TODO This is a local file (normal server): use 200.

        // Redirect (302/307) to the url of the file.
        $assetUrl = $this->viewHelpers()->get('assetUrl')->__invoke($placeholder, 'ImageServer');
        return $this->redirect()->toUrl($assetUrl);
    }

    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix
     * @param string $name The file name, or basename if extension is passed
     * @param string $extension The file extension
     */
    protected function getStoragePath(string $prefix, string $name, string $extension = ''): string
    {
        return sprintf('%s/%s%s', $prefix, $name, strlen($extension) ? '.' . $extension : '');
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
     * Get the requested version from the route, headers, or settings.
     */
    protected function requestedVersion(): string
    {
        // Check the version from the url first.
        $this->version = $this->params('version');
        if ($this->version === '2' || $this->version === '3') {
            return $this->version;
        }

        $accept = $this->getRequest()->getHeaders()->get('Accept')->toString();
        if (strpos($accept, 'iiif.io/api/image/3/context.json')) {
            $this->version = '3';
        } elseif (strpos($accept, 'iiif.io/api/image/2/context.json')) {
            $this->version = '2';
        } else {
            $this->version = $this->settings()->get('imageserver_info_default_version', '2') ?: '2';
        }
        return $this->version;
    }

    protected function jsonError(\Exception $exception, $statusCode = 500): JsonModel
    {
        $this->getResponse()->setStatusCode($statusCode);
        return new JsonModel([
            'status' => 'error',
            'message' => $exception->getMessage(),
        ]);
    }

    protected function viewError(\Exception $exception, $statusCode = 500): ViewModel
    {
        $this->getResponse()->setStatusCode($statusCode);
        $view = new ViewModel([
            'message' => $exception->getMessage(),
        ]);
        return $view
            ->setTemplate('image-server/image/error');
    }
}
