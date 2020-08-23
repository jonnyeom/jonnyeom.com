<?php

namespace App\Controller;

use App\Model\Post;
use App\Service\BlogContent;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Routing\Annotation\Route;

class BlogController extends BaseController
{
    /**
     * @var array
     */
    protected $posts = [
        'collection-of-drupal-errors' => [
            'title' => 'Collection of drupal errors',
            'description' => 'A collection of drupal errors I\'ve run into',
            'date' => 'July 30, 2020',
            'tags' => [
                'Drupal',
            ],
        ],
        'local-ansible-playbook-example-for-drupal-vm' => [
            'title' => 'Example local ansible playbook for Drupal-VM',
            'description' => 'An example of adding your own ansible roles/tasks for Drupal-VM',
            'date' => 'July 9, 2020',
            'tags' => [
                'Drupal',
                'Ansible',
            ],
        ],
        'vagrantfile-local-example-for-drupal-vm' => [
            'title' => 'Example local Vagrantfile for Drupal-VM',
            'description' => 'An example Vagrantfile.local file for Drupal-VM',
            'date' => 'July 9, 2020',
            'tags' => [
                'Drupal',
            ],
        ],
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
    public function index(BlogContent $blogContent)
    {
        $this->seo->get('basic')->setTitle('jonnyeom | Writing');
        $this->seo->get('og')->setTitle('jonnyeom | Writing');
        $this->seo->get('twitter')->setTitle('jonnyeom | Writing');

        $posts = $blogContent->getPosts();

        return $this->render('blog/index.html.twig', [
            'posts' => $posts,
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
            $object = YamlFrontMatter::parse(file_get_contents(__DIR__ . "/../Content/Post/{$slug}.md"));
            $post = Post::createFromYamlParse($object);

            $cache->save($post);
        }

        /** @var Post $post */
        $post = $item->get();

        $this->seo->get('basic')
            ->setTitle('jonnyeom | ' . $post->getTitle())
            ->setDescription($post->getDescription())
            ->setKeywords($post->getTags());

        $this->seo->get('og')
            ->setTitle('jonnyeom | ' . $post->getTitle())
            ->setDescription($post->getDescription());

        $this->seo->get('twitter')
            ->setTitle('jonnyeom | ' . $post->getTitle())
            ->setDescription($post->getDescription());

        return $this->render('blog/post.html.twig', [
            'body' => $post->getBody(),
        ]);
    }

}
