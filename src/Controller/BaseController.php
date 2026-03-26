<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BaseController extends AbstractController
{
    private string $seoTitle = 'jonnyeom | Jonathan Eom';
    private string $seoDescription = 'A professional developer, experienced in developing enterprise level websites and applications. Experienced in AngularJS, Drupal, GatsbyJS JavaScript, PHP, React, Symfony, Vue.';
    private string $seoKeywords = 'jonnyeom, Jonathan Eom, Jonny Eom, Drupal, Symfony, Developer';
    private string $seoImage = 'https://www.jonnyeom.com/images/jonnyeom.jpg';
    private string $seoUrl = 'https://www.jonnyeom.com/';

    #[Route(path: '/in-progress', name: 'app_in_progress')]
    public function inProgress(string $title = 'In Progress', mixed $content = null): Response
    {
        $this->setSeoTitle('jonnyeom | In Progress..');

        return $this->render('page/in-progress.html.twig', [
            'title' => $title,
            'content' => $content,
        ]);
    }

    protected function render(string $view, array $parameters = [], ?Response $response = null): Response
    {
        return parent::render($view, [
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'seo_keywords' => $this->seoKeywords,
            'seo_image' => $this->seoImage,
            'seo_url' => $this->seoUrl,
            ...$parameters,
        ], $response);
    }

    protected function setSeoTitle(string $title): void
    {
        $this->seoTitle = $title;
    }

    protected function setSeoDescription(string $description): void
    {
        $this->seoDescription = $description;
    }

    protected function setSeoKeywords(string $keywords): void
    {
        $this->seoKeywords = $keywords;
    }
}
