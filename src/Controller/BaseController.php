<?php

declare(strict_types=1);

namespace App\Controller;

use Rami\SeoBundle\Metas\MetaTagsManagerInterface;
use Rami\SeoBundle\OpenGraph\OpenGraphManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use function explode;

class BaseController extends AbstractController
{
    /** @param array<string, string> $metaTagsDefaults */
    public function __construct(
        protected MetaTagsManagerInterface $metaTags,
        protected OpenGraphManagerInterface $openGraph,
        #[Autowire('%seo.meta_tags%')]
        array $metaTagsDefaults = [],
    ) {
        if (! empty($metaTagsDefaults['title'])) {
            $this->metaTags->setTitle($metaTagsDefaults['title']);
        }

        if (! empty($metaTagsDefaults['description'])) {
            $this->metaTags->setDescription($metaTagsDefaults['description']);
        }

        $this->openGraph->setImage('https://www.jonnyeom.com/images/jonnyeom.jpg');
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
        $this->metaTags->setTitle($title);
        $this->openGraph->setTitle($title);
        $this->openGraph->addTwitterCardProperty('title', $title);
    }

    protected function setSeoDescription(string $description): void
    {
        $this->metaTags->setDescription($description);
        $this->openGraph->setDescription($description);
        $this->openGraph->addTwitterCardProperty('description', $description);
    }

    protected function setSeoKeywords(string $keywords): void
    {
        $this->metaTags->setKeywords(explode(',', $keywords));
    }
}
