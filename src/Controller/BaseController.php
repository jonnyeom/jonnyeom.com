<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BaseController extends AbstractController
{

    /**
     * @Route("/in-progress", name="app_in_progress")
     *
     * @param string $title
     * @return Response
     */
    public function inProgress($title = 'In Progress', $content = NULL): Response
    {
        return $this->render('page/in-progress.html.twig', [
            'title' => $title,
            'content' => $content,
        ]);
    }

}
