<?php

namespace App\Controller;

use App\FormType\TestFormType;
use ChildTrait;
use SampleInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Loader\Configurator\Traits\TagTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Annotation\Route;

class TestController extends AbstractController implements SampleInterface, TestControllerInterface
{
    use ChildTrait;

    public function interfaceFunction(string $param): int
    {
        // TODO: Implement interfaceFunction() method.
    }


    private function childTraitMethod(callable $param, $paramTwo): string
    {
        return 'aha';
    }

    private function abstractPrivateTraitMethod(): string
    {
        return '';
    }


    /**
     * @Route(
     *     "/test/default",
     *     methods={"GET"},
     *     name="test_default"
     * )
     */
    public function default()
    {
        $filename = tempnam(sys_get_temp_dir(), 'sf');
        file_put_contents($filename, 'hello {{ 1 + 1 }}');

        return $this->forward('FrameworkBundle:Template:template', [
            'template' => $filename
        ]);
//        $result = fopen('http://www.google.com/', 'r');
//
//        return $this->render('test/debug.html.twig', [
//            'vars' => $result,
//        ]);
    }

    /**
     * @Route(
     *     "/test/file-system",
     *     name="test_file_system",
     *     host="{test_domain}"
     *
     * )
     */
    public function fileSystem()
    {
        $fileSystem = new Filesystem();
        $canonicalizedLink = $fileSystem->readlink('/tmp/link', true);
        $data = [
            'first' => 0,
            'first-page' => 1
        ];
        $response = $this->render('test/debug.html.twig', ['page' => 5,  'data' => $data]);
        $response->headers->set('Vary', 'User-Agent', false);
        $response->headers->set('Vary', 'Accept-Encoding', false);
//        $response->headers->set('Vary', 'User-Agent');

        return $response;
        return $this->render('test/debug.html.twig', [
            'readLink' => $canonicalizedLink,
            'data' => $data,
        ]);
    }

    /**
     * @Route(
     *     "/test/forms",
     *     name="test_forms",
     * )
     */
    public function forms(Request $request)
    {
//        $forms[] = $this->createForm(TestFormType::class);
        $form = $this->createFormBuilder()
            ->add('time', DateTimeType::class)
            ->add('fooBar', CheckboxType::class, [
                'value' => 'bar',
                'required' => false,
            ])
            ->add('foo1', CheckboxType::class, [
                'value' => '1',
                'required' => false,
            ])
            ->add('foo0', CheckboxType::class, [
                'value' => '0',
                'required' => false,
            ])
            ->add('fooTrue', CheckboxType::class, [
                'value' => 'true',
                'required' => false,
            ])
            ->add('fooFalse', CheckboxType::class, [
                'value' => 'true',
                'required' => false,
            ])
            ->add('submit', SubmitType::class)
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            return $this->render('test/form-results.html.twig', [
                'form_data' => $data,
            ]);
        }

        $forms[] = $form;
        // @Todo Do any Form processing.

        foreach ($forms as &$form) {
            $form = $form->createView();
        }

        return $this->render('test/forms.html.twig', [
            'forms' => $forms,
        ]);
    }

    public static function staticInterfaceFunction()
    {
        // TODO: Implement staticInterfaceFunction() method.
    }
}
