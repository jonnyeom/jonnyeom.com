<?php

declare(strict_types=1);

namespace App\Controller;

use Leogout\Bundle\SeoBundle\Provider\SeoGeneratorProvider;
use Leogout\Bundle\SeoBundle\Seo\Basic\BasicSeoGenerator;
use Leogout\Bundle\SeoBundle\Seo\Og\OgSeoGenerator;
use Leogout\Bundle\SeoBundle\Seo\Twitter\TwitterSeoGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use function assert;

class BaseController extends AbstractController
{
    public function __construct(protected SeoGeneratorProvider $seo)
    {
    }

    #[Route(path: '/in-progress', name: 'app_in_progress')]
    public function inProgress(string $title = 'In Progress', mixed $content = null): Response
    {
        $this->setSeoTitle('jonnyeom | In Progress..');

        return $this->render('page/in-progress.html.twig', [
            'title' => $title,
            'content' => $content,
        ]);
    }

    protected function setSeoTitle(string $title): void
    {
        $basicSeoGenerator = $this->seo->get('basic');
        assert($basicSeoGenerator instanceof BasicSeoGenerator);
        $basicSeoGenerator->setTitle($title);

        $ogSeoGenerator = $this->seo->get('og');
        assert($ogSeoGenerator instanceof OgSeoGenerator);
        $ogSeoGenerator->setTitle($title);

        $twitterSeoGenerator = $this->seo->get('twitter');
        assert($twitterSeoGenerator instanceof TwitterSeoGenerator);
        $twitterSeoGenerator->setTitle($title);
    }

    protected function setSeoDescription(string $description): void
    {
        $basicSeoGenerator = $this->seo->get('basic');
        assert($basicSeoGenerator instanceof BasicSeoGenerator);
        $basicSeoGenerator->setDescription($description);

        $ogSeoGenerator = $this->seo->get('og');
        assert($ogSeoGenerator instanceof OgSeoGenerator);
        $ogSeoGenerator->setDescription($description);

        $twitterSeoGenerator = $this->seo->get('twitter');
        assert($twitterSeoGenerator instanceof TwitterSeoGenerator);
        $twitterSeoGenerator->setDescription($description);
    }

    protected function setSeoKeywords(string $keywords): void
    {
        $basicSeoGenerator = $this->seo->get('basic');
        assert($basicSeoGenerator instanceof BasicSeoGenerator);
        $basicSeoGenerator->setKeywords($keywords);
    }
}
