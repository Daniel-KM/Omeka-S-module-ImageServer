<?php declare(strict_types=1);

namespace ImageServer\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class TileInfo extends AbstractHelper
{
    /**
     * @var \ImageServer\Mvc\Controller\Plugin\TileInfo
     */
    protected $tileInfoPlugin;

    public function __construct(\ImageServer\Mvc\Controller\Plugin\TileInfo $tileInfoPlugin)
    {
        $this->tileInfoPlugin = $tileInfoPlugin;
    }

    /**
     * Retrieve info about the tiling of an image. Accepts any resource carrying
     * the same storageId / mediaData layout, including DigitalObject canvases.
     */
    public function __invoke(AbstractResourceEntityRepresentation $media): ?array
    {
        return $this->tileInfoPlugin->__invoke($media);
    }
}
