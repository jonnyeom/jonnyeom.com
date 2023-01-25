<?php

namespace App\Controller;

use Leogout\Bundle\SeoBundle\Provider\SeoGeneratorProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BaseController extends AbstractController
{

    /**
     * @var SeoGeneratorProvider
     */
    protected $seo;

    public function __construct(SeoGeneratorProvider $seoGeneratorProvider)
    {
        $this->seo = $seoGeneratorProvider;
    }

    /**
     *
     * @param string $title
     *
     * @return Response
     */
    #[Route(path: '/in-progress', name: 'app_in_progress')]
    public function inProgress($title = 'In Progress', $content = null): Response
    {
        $this->seo->get('basic')->setTitle('jonnyeom | In Progress..');

        return $this->render('page/in-progress.html.twig', [
            'title' => $title,
            'content' => $content,
        ]);
    }
}
