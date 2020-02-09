<?php
namespace ImageServer\Service\Form;

use ImageServer\Form\ConfigForm;
use Zend\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;

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
