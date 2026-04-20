<?php declare(strict_types=1);

namespace ImageServer\Service\Form;

use ImageServer\Form\ConfigForm;
use ImageServer\ImageServer\Vips;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Omeka\File\Thumbnailer\ImageMagick;
use Omeka\Stdlib\Cli;
use Psr\Container\ContainerInterface;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // Silent Cli for feature probes (config form is only used to detect
        // which imagers are available on the host).
        $cli = $services->get('ImageServer\Stdlib\CliNoLog');

        $imagers = [
            'Auto' => [
                'value' => 'Auto',
                'label' => 'Automatic (php-vips, else Vips CLI, else GD, else Imagick, else ImageMagick)', // @translate
            ],
            'PhpVips' => [
                'value' => 'PhpVips',
                'label' => 'php-vips (library, fastest)', // @translate
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
        $phpVipsAvailable = false;
        if (class_exists('Jcupitt\Vips\Image')) {
            try {
                \Jcupitt\Vips\Image::black(1, 1);
                $phpVipsAvailable = true;
            } catch (\Throwable $e) {
            }
        }
        if (!$phpVipsAvailable) {
            $imagers['PhpVips']['disabled'] = true;
        }
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
