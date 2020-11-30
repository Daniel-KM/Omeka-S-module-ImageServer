<?php declare(strict_types=1);

namespace ImageServer\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\MediaRepresentation;

class TileMediaInfo extends AbstractHelper
{
    /**
     * @var \ImageServer\Mvc\Controller\Plugin\TileMediaInfo
     */
    protected $tileMediaInfoPlugin;

    /**
     * @param \ImageServer\Mvc\Controller\Plugin\TileMediaInfo $tileMediaInfoPlugin
     */
    public function __construct(\ImageServer\Mvc\Controller\Plugin\TileMediaInfo $tileMediaInfoPlugin)
    {
        $this->tileMediaInfoPlugin = $tileMediaInfoPlugin;
    }

    /**
     * Retrieve info about the tiling of an image.
     *
     * @param MediaRepresentation $media
     * @return array|null
     */
    public function __invoke(MediaRepresentation $media): ?array
    {
        return $this->tileMediaInfoPlugin->__invoke($media);
    }
}
