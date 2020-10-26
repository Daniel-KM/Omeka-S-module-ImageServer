<?php declare(strict_types=1);
namespace ImageServer\Service\Form;

use ImageServer\Form\ConfigForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $translator = $services->get('MvcTranslator');

        $form = new ConfigForm(null, $options);
        $form->setTranslator($translator);
        return $form;
    }
}
