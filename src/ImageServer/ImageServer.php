<?php declare(strict_types=1);

/*
 * Copyright 2015-2023 Daniel Berthereau
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

namespace ImageServer\ImageServer;

use Laminas\Log\LoggerAwareInterface;
use Laminas\Log\LoggerAwareTrait;
use Laminas\Log\LoggerInterface;
use Omeka\File\Store\StoreInterface;
use Omeka\File\TempFileFactory;
use Omeka\Settings\Settings;
use Omeka\Stdlib\Message;

/**
 * Helper to create an image from another one with IIIF arguments.
 *
 * @todo Create a service for image server and use a manager.
 *
 * @package ImageServer
 */
class ImageServer implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AbstractImager
     */
    protected $imager;

    /**
     * @var array
     */
    protected $args = [];

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * @var array
     */
    protected $commandLineArgs;

    public function __construct(
        TempFileFactory $tempFileFactory,
        $store,
        array $commandLineArgs,
        Settings $settings,
        LoggerInterface $logger
    ) {
        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;
        $this->commandLineArgs = $commandLineArgs;
        $this->setLogger($logger);
        $imagerClass = $settings->get('imageserver_image_creator', 'Auto');
        $this->setImager('\\ImageServer\\ImageServer\\' . $imagerClass);
    }

    public function setImager($imagerClass): self
    {
        $imagerClasses = [
            '\\ImageServer\\ImageServer\\Auto',
            '\\ImageServer\\ImageServer\\GD',
            '\\ImageServer\\ImageServer\\Imagick',
            '\\ImageServer\\ImageServer\\ImageMagick',
        ];
        if (!in_array($imagerClass, $imagerClasses)) {
            throw new \RuntimeException((string) new Message(
                'The imager "%s" is not supported.', // @translate
                $imagerClass
            ));
        }
        $needCli = [
            '\\ImageServer\\ImageServer\\Auto',
            '\\ImageServer\\ImageServer\\ImageMagick',
            '\\ImageServer\\ImageServer\\Vips',
        ];
        $this->imager = in_array($imagerClass, $needCli)
            ? new $imagerClass($this->tempFileFactory, $this->store, $this->commandLineArgs)
            : new $imagerClass($this->tempFileFactory, $this->store);
        $this->imager->setLogger($this->getLogger());
        return $this;
    }

    public function getImager(): ?AbstractImager
    {
        return $this->imager;
    }

    public function setArgs(array $args): self
    {
        $this->args = $args;
        return $this;
    }

    /**
     * Transform an image into another image according to params.
     *
     * Note: The args are currently already checked in the controller.
     *
     * @param array $args List of arguments for the transformation.
     * @return string|null The filepath to the temp image if success.
     */
    public function transform(array $args = null): ?string
    {
        if (!is_null($args)) {
            $this->setArgs($args);
        }
        return $this->imager
            ->transform($this->args);
    }
}
