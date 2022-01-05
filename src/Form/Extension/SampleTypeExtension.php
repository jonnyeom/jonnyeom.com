<?php

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

class SampleTypeExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [
            FormType::class,
        ];
    }

    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['sample_data'] = 'Data from Sample Type Extension!';
    }

}
