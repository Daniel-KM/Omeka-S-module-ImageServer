<?php declare(strict_types=1);

namespace ImageServer\Service\Form;

use ImageServer\Form\ConfigForm;
use ImageServer\ImageServer\Vips;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Omeka\File\Thumbnailer\ImageMagick;
use Omeka\Stdlib\Cli;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $cli = $services->get('Omeka\Cli');

        $imagers = [
            'Auto' => [
                'value' => 'Auto',
                'label' => 'Automatic (Vips when possible, else GD, else Imagick, else ImageMagick)', // @translate
            ],
            'Vips' => [
                'value' => 'Vips',
                'label' => 'Vips (command line)', // @translate
            ],
            'GD' => [
                'value' => 'GD',
                'label' => 'GD (php extension)', // @translate
            ],
            'Imagick' => [
                'value' => 'Imagick',
                'label' => 'Imagick (php extension)', // @translate
            ],
            'ImageMagick' => [
                'value' => 'ImageMagick',
                'label' => 'ImageMagick (command line)', // @translate
            ],
        ];
        $dir = $services->get('Omeka\Settings')->get('imageserver_vips_dir');
        $validate = $this->getPath($cli, $dir, Vips::VIPS_COMMAND);
        if (!$validate) {
            $imagers['Vips']['disabled'] = true;
        }
        $dir = $services->get('Config')['thumbnails']['thumbnailer_options']['imagemagick_dir'];
        $validate = $this->getPath($cli, $dir, ImageMagick::CONVERT_COMMAND);
        if (!$validate) {
            $imagers['ImageMagick']['disabled'] = true;
        }
        if (!extension_loaded('gd')) {
            $imagers['GD']['disabled'] = true;
        }
        if (!extension_loaded('imagick')) {
            $imagers['Imagick']['disabled'] = true;
        }

        $imageServer = $services->get('ControllerPluginManager')->get('imageServer');

        $form = new ConfigForm(null, $options ?? []);
        return $form
            ->setTranslator($services->get('MvcTranslator'))
            ->setImageServer($imageServer())
            ->setImagers($imagers);
    }

    /**
     * Check and get the path of a command.
     *
     * @param Cli $cli
     * @param string $dir
     * @param string $command
     * @return string
     */
    protected function getPath(Cli $cli, ?string $dir, string $command): string
    {
        return $dir
            ? (string) $cli->validateCommand($dir, $command)
            : (string) $cli->getCommandPath($command);
    }
}
