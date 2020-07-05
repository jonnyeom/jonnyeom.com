<?php

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends BaseController
{
    /**
     * @Route("/", name="app_homepage")
     */
    public function index(AdapterInterface $cache): Response
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

        $this->seo->get('basic')->setTitle('jonnyeom | Home');
        $this->seo->get('og')->setTitle('jonnyeom | Home');
        $this->seo->get('twitter')->setTitle('jonnyeom | Home');

        return $this->render('page/homepage.html.twig', [
            'content' => $content,
        ]);
    }

    /**
     * @Route("/projects", name="app_projects")
     */
    public function projects(): Response
    {
        // Get projects.
        $json = file_get_contents(__DIR__ . '/../Content/Projects.json');
        $projects = json_decode($json, true);
        $tag_mappings = [
            'Drupal 8' => 'is-drupal-blue',
            'React' => 'is-react-blue',
            'Vue.js' => 'is-vue-green',
            'Commerce' => 'is-warning',
            'default' => 'is-theme-blue',
        ];

        // Map the proper classes to tags.
        foreach ($projects as &$project) {
            foreach ($project['tags'] as &$tag) {
                $tag_class = $tag_mappings[$tag] ?? $tag_mappings['default'];
                $tag = [
                    'label' => $tag,
                    'class' => $tag_class,
                ];
            }
        }

        $this->seo->get('basic')->setTitle('jonnyeom | Projects');
        $this->seo->get('og')->setTitle('jonnyeom | Projects');
        $this->seo->get('twitter')->setTitle('jonnyeom | Projects');

        return $this->render('page/projects.html.twig', [
            'projects' => $projects,
        ]);
    }

    /**
     * @Route("/about", name="app_about")
     */
    public function about(AdapterInterface $cache): Response
    {
        // Get main page content.
        $item = $cache->getItem('markdown_about');
        if (!$item->isHit()) {
            $converter = new CommonMarkConverter();
            $markdown = file_get_contents(__DIR__ . '/../Content/About.md');
            $item->set($converter->convertToHtml($markdown));
            $cache->save($item);
        }
        $content = $item->get();

        $this->seo->get('basic')->setTitle('jonnyeom | About');
        $this->seo->get('og')->setTitle('jonnyeom | About');
        $this->seo->get('twitter')->setTitle('jonnyeom | About');

        return $this->render('page/about.html.twig', [
            'content' => $content,
        ]);
    }

}
