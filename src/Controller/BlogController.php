<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Post;
use App\Service\BlogContent;
use Leogout\Bundle\SeoBundle\Provider\SeoGeneratorProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use function implode;
use function md5;
use function uasort;

class BlogController extends BaseController
{
    public function __construct(SeoGeneratorProvider $seoGeneratorProvider, private readonly BlogContent $blogContent)
    {
        parent::__construct($seoGeneratorProvider);
    }

    #[Route(path: '/writing', name: 'app_posts')]
    public function index(): Response
    {
        $this->setSeoTitle('jonnyeom | Writing');

        $posts = $this->blogContent->getPosts();

        uasort($posts, static fn (Post $postA, Post $postB) => $postB->getDate() <=> $postA->getDate());

        return $this->render('blog/index.html.twig', ['posts' => $posts]);
    }

    #[Route(path: '/writing/{slug}', name: 'app_post')]
    public function post(string $slug, Request $request): Response
    {
        $post = $this->blogContent->getPost($slug);

        if (! $post) {
            return $this->redirectToRoute('app_posts');
        }

        $this->setSeoTitle($post->getTitle() . ' | jonnyeom');
        if ($post->getDescription()) {
            $this->setSeoDescription($post->getDescription());
        }

        $this->setSeoKeywords(implode(',', $post->getTags()));

        $response = $this->render('blog/post.html.twig', ['post' => $post]);

        if (! $response->getContent()) {
            throw $this->createNotFoundException('Blog Content not found');
        }

        $response->setEtag(md5($response->getContent()));
        $response->setPublic();
        $response->isNotModified($request);

        return $response;
    }
}
