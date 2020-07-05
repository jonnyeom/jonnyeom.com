<?php

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Routing\Annotation\Route;

class BlogController extends BaseController
{
    /**
     * @var array
     */
    protected $posts = [
        'upgrade-symfony-from-44-to-51' => [
            'title' => 'Upgrading my symfony application from 4.4 to 5.1',
            'description' => 'Just a short overview of what I did to update my symfony applications to 5.1',
            'date' => 'July 4, 2020',
            'tags' => [
                'Symfony',
            ],
        ],
        'style_guide' => [
            'title' => 'Style Guide',
            'description' => 'A simple style guide',
            'date' => 'April 9, 2020',
            'tags' => [
                'Drupal',
                'Test',
            ],
        ],
    ];

    /**
     * @Route("/writing", name="app_blog")
     */
    public function index()
    {
        $this->seo->get('basic')->setTitle('jonnyeom | Writing');
        $this->seo->get('og')->setTitle('jonnyeom | Writing');
        $this->seo->get('twitter')->setTitle('jonnyeom | Writing');

        return $this->render('blog/index.html.twig', [
            'posts' => $this->posts,
        ]);
    }

    /**
     * @Route("/writing/{slug}", name="app_blog_post")
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
            // @Todo: Move to a wrapper service.
            // Obtain a pre-configured Environment with all the CommonMark parsers/renderers ready-to-go
            $environment = Environment::createCommonMarkEnvironment();
            // Add this extension
            $environment->addExtension(new ExternalLinkExtension());
            // Set your configuration
            $config = [
                'external_link' => [
                    'internal_hosts' => 'www.jonnyeom.com', // TODO: Don't forget to set this!
                    'open_in_new_window' => true,
                    'html_class' => 'external-link',
                    'nofollow' => '',
                    'noopener' => 'external',
                    'noreferrer' => 'external',
                ],
            ];
            $converter = new CommonMarkConverter($config, $environment);
            $markdown = file_get_contents(__DIR__ . "/../Content/Post/{$slug}.md");
            $item->set($converter->convertToHtml($markdown));
            $cache->save($item);
        }
        $content = $item->get();

        $this->seo->get('basic')
            ->setTitle('jonnyeom | ' . $this->posts[$slug]['title'])
            ->setDescription($this->posts[$slug]['description'])
            ->setKeywords(implode(',', $this->posts[$slug]['tags']));

        $this->seo->get('og')
            ->setTitle('jonnyeom | ' . $this->posts[$slug]['title'])
            ->setDescription($this->posts[$slug]['description']);

        $this->seo->get('twitter')
            ->setTitle('jonnyeom | ' . $this->posts[$slug]['title'])
            ->setDescription($this->posts[$slug]['description']);

        return $this->render('blog/post.html.twig', [
            'content' => $content,
        ]);
    }

}
