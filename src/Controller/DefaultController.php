<?php

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractController
{
    /**
     * @Route("/", name="app_homepage")
     */
    public function index(AdapterInterface $cache)
    {
        $item = $cache->getItem('markdown_homepage');
        if (!$item->isHit()) {
            $converter = new CommonMarkConverter();
            $markdown = file_get_contents(__DIR__ . '/../Content/Homepage.md');
            $item->set($converter->convertToHtml($markdown));
            $cache->save($item);
        }
        $content = $item->get();

        return $this->render('default/homepage.html.twig', [
            'controller_name' => 'DefaultController',
            'content' => $content,
        ]);
    }

    /**
     * @Route("/blog", name="app_blog")
     */
    public function blog()
    {
        return $this->inProgress('Blog');
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

    /**
     * @Route("/in-progress", name="app_in_progress")
     *
     * @param string $title
     * @return Response
     */
    private function inProgress($title = 'In Progress'): Response
    {
        return $this->render('default/in-progress.html.twig', [
            'controller_name' => 'DefaultController',
            'title' => $title,
        ]);
    }
}
