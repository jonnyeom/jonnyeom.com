<?php

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Routing\Annotation\Route;

class BlogController extends BaseController
{
    /**
     * @var array
     */
    protected $posts = [
        'style_guide' => [
            'title' => 'Style Guide',
            'date' => 'April 9, 2020',
            'tags' => [
                'Drupal',
                'Test',
            ],
        ],
    ];

    /**
     * @Route("/blog", name="app_blog")
     */
    public function index()
    {
        return $this->render('blog/index.html.twig', [
            'short_title' => 'Blog',
            'posts' => $this->posts,
        ]);
    }

    /**
     * @Route("/blog/{slug}", name="app_blog_post")
     */
    public function post($slug, AdapterInterface $cache)
    {
        // Check if its a valid post.
        if (empty($this->posts[$slug])) {
            return $this->redirectToRoute('app_blog');
        }

        $cid = 'post_' . $slug;
        $item = $cache->getItem($cid);
        if (!$item->isHit()) {
            $converter = new CommonMarkConverter();
            $markdown = file_get_contents(__DIR__ . "/../Content/Post/{$slug}.md");
            $item->set($converter->convertToHtml($markdown));
            $cache->save($item);
        }
        $content = $item->get();

        return $this->render('blog/post.html.twig', [
            'short_title' => $this->posts[$slug]['title'],
            'content' => $content,
        ]);
    }

}
