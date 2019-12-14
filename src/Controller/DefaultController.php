<?php

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends BaseController
{
    /**
     * @Route("/", name="app_homepage")
     */
    public function index(AdapterInterface $cache)
    {
        // Get main page content.
        $item = $cache->getItem('markdown_homepage');
        if (!$item->isHit()) {
            $converter = new CommonMarkConverter();
            $markdown = file_get_contents(__DIR__ . '/../Content/Homepage.md');
            $item->set($converter->convertToHtml($markdown));
            $cache->save($item);
        }
        $content = $item->get();

        // Get bottom page content.
        $item = $cache->getItem('markdown_homepage_bottom');
        if (!$item->isHit()) {
            $converter = new CommonMarkConverter();
            $markdown = file_get_contents(__DIR__ . '/../Content/Homepage-bottom.md');
            $item->set($converter->convertToHtml($markdown));
            $cache->save($item);
        }
        $content_bottom = $item->get();

        return $this->render('default/homepage.html.twig', [
            'content' => $content,
            'content_bottom' => $content_bottom,
        ]);
    }

    /**
     * @Route("/projects", name="app_projects")
     */
    public function projects()
    {
        return $this->inProgress('Projects');
    }

    /**
     * @Route("/about", name="app_about")
     */
    public function about()
    {
        return $this->inProgress('About');
    }

}
