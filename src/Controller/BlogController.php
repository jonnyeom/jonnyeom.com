<?php

namespace App\Controller;

use App\Service\BlogContent;
use Leogout\Bundle\SeoBundle\Provider\SeoGeneratorProvider;
use Symfony\Component\Routing\Annotation\Route;

class BlogController extends BaseController
{
    /**
     * @var BlogContent
     */
    private $blogContent;

    /**
     * BlogController constructor.
     *
     * @param SeoGeneratorProvider $seoGeneratorProvider
     * @param BlogContent $blogContent
     */
    public function __construct(SeoGeneratorProvider $seoGeneratorProvider, BlogContent $blogContent)
    {
        parent::__construct($seoGeneratorProvider);

        $this->blogContent = $blogContent;
    }

    /**
     * @Route("/writing", name="app_posts")
     */
    public function index()
    {
        $this->seo->get('basic')->setTitle('jonnyeom | Writing');
        $this->seo->get('og')->setTitle('jonnyeom | Writing');
        $this->seo->get('twitter')->setTitle('jonnyeom | Writing');

        $posts = $this->blogContent->getPosts();

        return $this->render('blog/index.html.twig', [
            'posts' => $posts,
        ]);
    }

    /**
     * @Route("/writing/{slug}", name="app_post")
     */
    public function post($slug)
    {
        $post = $this->blogContent->getPost($slug);

        if (!$post) {
            return $this->redirectToRoute('app_posts');
        }

        $this->seo->get('basic')
            ->setTitle($post->getTitle() . '| jonnyeom')
            ->setDescription($post->getDescription())
            ->setKeywords(implode(',', $post->getTags()));

        $this->seo->get('og')
            ->setTitle($post->getTitle() . '| jonnyeom')
            ->setDescription($post->getDescription());

        $this->seo->get('twitter')
            ->setTitle($post->getTitle() . '| jonnyeom')
            ->setDescription($post->getDescription());

        return $this->render('blog/post.html.twig', [
            'post' => $post,
        ]);
    }

}
