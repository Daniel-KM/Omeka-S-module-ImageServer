<?php declare(strict_types=1);

namespace ImageServer\Service\Form;

use ImageServer\Form\ConfigForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $cli = $services->get('Omeka\Cli');

        $imagers = [
            'Auto' => [
                'value' => 'Auto',
                'label' => 'Automatic (GD when possible, else Imagick, else ImageMagick)', // @translate
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
        $dir = $services->get('Config')['thumbnails']['thumbnailer_options']['imagemagick_dir'];
        $validate = ($dir ? $cli->validateCommand($dir . 'convert') : $cli->getCommandPath('convert')) !== false;
        if (!$validate) {
            $imagers['ImageMagick']['disabled'] = true;
        }
        if (!extension_loaded('gd')) {
            $imagers['GD']['disabled'] = true;
        }
        if (!extension_loaded('imagick')) {
            $imagers['Imagick']['disabled'] = true;
        }

        $form = new ConfigForm(null, $options);
        return $form
            ->setTranslator($services->get('MvcTranslator'))
            ->setImagers($imagers);
    }
}
