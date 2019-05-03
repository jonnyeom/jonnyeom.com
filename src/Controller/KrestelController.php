<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class KrestelController extends AbstractController
{
    /**
     * @Route("/krestel-light", name="app_krestel_light")
     */
    public function index()
    {
        return $this->render('krestel/index.html.twig', [
            'controller_name' => 'KrestelController',
        ]);
    }
}
