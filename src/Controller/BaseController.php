<?php

declare(strict_types=1);

namespace App\Controller;

use Leogout\Bundle\SeoBundle\Provider\SeoGeneratorProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BaseController extends AbstractController
{
    public function __construct(protected SeoGeneratorProvider $seo)
    {
    }

    #[Route(path: '/in-progress', name: 'app_in_progress')]
    public function inProgress(string $title = 'In Progress', mixed $content = null): Response
    {
        $this->seo->get('basic')->setTitle('jonnyeom | In Progress..');

        return $this->render('page/in-progress.html.twig', [
            'title' => $title,
            'content' => $content,
        ]);
    }
}
