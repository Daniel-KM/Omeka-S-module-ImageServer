<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class ImageServer extends AbstractPlugin
{
    /**
     * @var \ImageServer\ImageServer\ImageServer
     */
    protected $imageServer;

    public function __construct(\ImageServer\ImageServer\ImageServer $imageServer)
    {
        $this->imageServer = $imageServer;
    }

    /**
     * Get the image server.
     *
     * @return \ImageServer\ImageServer\ImageServer
     */
    public function __invoke(): \ImageServer\ImageServer\ImageServer
    {
        return $this->imageServer;
    }
}
