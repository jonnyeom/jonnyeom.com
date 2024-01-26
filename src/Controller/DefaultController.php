<?php

declare(strict_types=1);

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

use function assert;
use function file_get_contents;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class DefaultController extends BaseController
{
    #[Route(path: '/', name: 'app_homepage')]
    public function index(): Response
    {
        $cache = new FilesystemAdapter();

        // Get main page content.
        $content = $cache->get('markdown_homepage', static function (ItemInterface $item) {
            $converter = new CommonMarkConverter();
            $markdown  = file_get_contents(__DIR__ . '/../Content/Homepage.md');
            assert(is_string($markdown));

            return $converter->convert($markdown);
        });

        $this->setSeoTitle('jonnyeom | Home');

        return $this->render('page/homepage.html.twig', ['content' => $content]);
    }

    #[Route(path: '/projects', name: 'app_projects')]
    public function projects(): Response
    {
        // Get projects.
        $json = file_get_contents(__DIR__ . '/../Content/Projects.json');
        assert(is_string($json));
        $projects    = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $tagMappings = [
            'Drupal 9' => 'is-drupal-blue',
            'Drupal 8' => 'is-drupal-blue',
            'React' => 'is-react-blue',
            'Vue.js' => 'is-vue-green',
            'Commerce' => 'is-warning',
            'default' => 'is-theme-blue',
        ];

        // Map the proper classes to tags.
        foreach ($projects as &$project) {
            foreach ($project['tags'] as &$tag) {
                $tagClass = $tagMappings[$tag] ?? $tagMappings['default'];
                $tag      = [
                    'label' => $tag,
                    'class' => $tagClass,
                ];
            }
        }

        $this->setSeoTitle('jonnyeom | Projects');

        return $this->render('page/projects.html.twig', ['projects' => $projects]);
    }

    #[Route(path: '/about', name: 'app_about')]
    public function about(): Response
    {
        $cache = new FilesystemAdapter();

        $content = $cache->get('markdown_about', static function (ItemInterface $item) {
            $converter = new CommonMarkConverter();
            $markdown  = file_get_contents(__DIR__ . '/../Content/About.md');
            assert(is_string($markdown));

            return $converter->convert($markdown);
        });

        $this->setSeoTitle('jonnyeom | About');

        return $this->render('page/about.html.twig', ['content' => $content]);
    }

    #[Route(path: '/experiments', name: 'app_experiments')]
    public function experiments(): Response
    {
        return $this->render('page/experiments.html.twig');
    }
}
