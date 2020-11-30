<?php declare(strict_types=1);

namespace ImageServer\Mvc\Controller\Plugin;

use DanielKm\Deepzoom\DeepzoomFactory;
use DanielKm\Zoomify\ZoomifyFactory;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Service\Exception\InvalidArgumentException;
use Omeka\Stdlib\Message;

class TileBuilder extends AbstractPlugin
{
    /**
     * Extension added to a folder name to store tiles for Deepzoom.
     *
     * @var string
     */
    const FOLDER_EXTENSION_DEEPZOOM = '_files';

    /**
     * Extension added to a file to store data for Deepzoom.
     *
     * @var string
     */
    const FOLDER_EXTENSION_DEEPZOOM_FILE = '.dzi';

    /**
     * Extension added to a folder name to store data and tiles for Zoomify.
     *
     * @var string
     */
    const FOLDER_EXTENSION_ZOOMIFY = '_zdata';

    /**
     * Extension added to a file to store jpeg2000.
     *
     * @var string
     */
    const FOLDER_EXTENSION_JPEG2000_FILE = '.jp2';

    /**
     * Extension added to a file to store tiff.
     *
     * @var string
     */
    const FOLDER_EXTENSION_TIFF_FILE = '.tif';

    /**
     * @var ConvertToImage $convertToImage
     */
    protected $convertToImage;

    /**
     * @param ConvertToImage $convertToImage
     */
    public function __construct(ConvertToImage $convertToImage)
    {
        $this->convertToImage = $convertToImage;
    }

    /**
     * Convert the source into tiles of the specified format and store them.
     *
     * @param string $filepath The path to the image.
     * @param string $destination The directory where to store the tiles.
     * @param array $params The processor to use or the path to a command.
     * @return array Info on result, the tile dir and the tile data file if any.
     */
    public function __invoke($source, $destination, array $params = [])
    {
        $source = realpath($source);
        if (empty($source)) {
            throw new InvalidArgumentException('Source is empty.'); // @translate
        }

        if (!is_file($source) || !is_readable($source)) {
            throw new InvalidArgumentException((string) new Message(
                'Source file "%s" is not readable.', // @translate
                $source
            ));
        }

        if (empty($destination)) {
            throw new InvalidArgumentException('Destination is empty.'); // @translate
        }

        $params['destinationRemove'] = !empty($params['destinationRemove']);

        if (empty($params['storageId'])) {
            $params['storageId'] = pathinfo($source, PATHINFO_FILENAME);
        }

        $result = [];
        $tileType = $params['tile_type'];
        unset($params['tile_type']);
        switch ($tileType) {
            case 'deepzoom':
                $factory = new DeepzoomFactory;
                $result['tile_dir'] = $destination . DIRECTORY_SEPARATOR . basename($params['storageId']) . self::FOLDER_EXTENSION_DEEPZOOM;
                $result['tile_file'] = $destination . DIRECTORY_SEPARATOR . basename($params['storageId']) . self::FOLDER_EXTENSION_DEEPZOOM_FILE;
                break;
            case 'zoomify':
                $factory = new ZoomifyFactory;
                $destination .= DIRECTORY_SEPARATOR . basename($params['storageId']) . self::FOLDER_EXTENSION_ZOOMIFY;
                $result['tile_dir'] = $destination;
                $result['tile_file'] = $destination . DIRECTORY_SEPARATOR . 'ImageProperties.xml';
                break;
            case 'jpeg2000':
                $result['tile_file'] = $destination . DIRECTORY_SEPARATOR . basename($params['storageId']) . self::FOLDER_EXTENSION_JPEG2000_FILE;
                $result['tile_dir'] = null;
                if (!$params['destinationRemove'] && file_exists($result['tile_file'])) {
                    $result['result'] = true;
                    $result['skipped'] = true;
                    return $result;
                }
                $params['media_type'] = 'image/jp2';
                $result['result'] = $this->convertToImage->__invoke($source, $result['tile_file'], $params);
                return $result;
            case 'tiled_tiff':
                $result['tile_file'] = $destination . DIRECTORY_SEPARATOR . basename($params['storageId']) . self::FOLDER_EXTENSION_TIFF_FILE;
                $result['tile_dir'] = null;
                if (!$params['destinationRemove'] && file_exists($result['tile_file'])) {
                    $result['result'] = true;
                    $result['skipped'] = true;
                    return $result;
                }
                $params['media_type'] = 'image/tiff';
                $result['result'] = $this->convertToImage->__invoke($source, $result['tile_file'], $params);
                return $result;
            default:
                throw new InvalidArgumentException((string) new Message(
                    'The type of tiling "%s" is not supported by the tile builder.', // @translate
                    $tileType
                ));
        }

        // Remove only the specified type, not other ones.
        if (!$params['destinationRemove']) {
            if (!empty($result['tile_file']) && file_exists($result['tile_file'])) {
                $result['result'] = true;
                $result['skipped'] = true;
                return $result;
            }
        }

        $processor = $factory($params);
        $result['result'] = $processor->process($source, $destination);

        return $result;
    }
}
