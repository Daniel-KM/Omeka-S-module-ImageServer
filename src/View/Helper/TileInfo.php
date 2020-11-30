<?php declare(strict_types=1);

namespace ImageServer\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\MediaRepresentation;

class TileInfo extends AbstractHelper
{
    /**
     * @var \ImageServer\Mvc\Controller\Plugin\TileInfo
     */
    protected $tileInfoPlugin;

    /**
     * @param \ImageServer\Mvc\Controller\Plugin\TileInfo $tileInfoPlugin
     */
    public function __construct(\ImageServer\Mvc\Controller\Plugin\TileInfo $tileInfoPlugin)
    {
        $this->tileInfoPlugin = $tileInfoPlugin;
    }

    /**
     * Retrieve info about the tiling of an image.
     *
     * @param MediaRepresentation $media
     * @return array|null
     */
    public function __invoke(MediaRepresentation $media): ?array
    {
        return $this->tileInfoPlugin->__invoke($media);
    }
}
