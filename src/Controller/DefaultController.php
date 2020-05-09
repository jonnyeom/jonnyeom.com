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

        return $this->render('default/projects.html.twig', [
            'projects' => $projects,
        ]);
    }

    /**
     * @Route("/cheatsheets", name="app_cheatsheets")
     */
    public function cheatsheets(): Response
    {
        return $this->inProgress('Cheatsheets', 'https://github.com/jonnyeom/cheatsheet-md/blob/master/All.md');
    }

    /**
     * @Route("/about", name="app_about")
     */
    public function about(): Response
    {
        return $this->inProgress('About');
    }

}
