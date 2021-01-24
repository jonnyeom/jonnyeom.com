<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Form\PostType;
use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller used to manage blog contents.
 */
class BlogController extends AbstractController
{
    /**
     * Lists all Posts.
     *
     * @Route("/admin", methods="GET", name="admin_index")
     * @Route("/admin", methods="GET", name="admin_post_list")
     */
    public function index(PostRepository $postRepository): Response
    {
        $posts = $postRepository->findBy([], ['publishedAt' => 'DESC']);

        return $this->render('admin/post/list.html.twig', ['posts' => $posts]);
    }

    /**
     * Creates a new Post.
     *
     * @Route("/admin/new", methods="GET|POST", name="admin_post_new")
     */
    public function new(Request $request): Response
    {
        $post = new Post();

        $form = $this->createForm(PostType::class, $post)
            ->add('saveAndCreateNew', SubmitType::class);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($post);
            $em->flush();

            $this->addFlash('success', 'post.created_successfully');

            if ($form->get('saveAndCreateNew')->isClicked()) {
                return $this->redirectToRoute('admin_post_new');
            }

            // @todo Change this to listing page.
            return $this->redirectToRoute('admin_post_new');
        }

        return $this->render('admin/post/new.html.twig', [
            'post' => $post,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Edit a post.
     *
     * @Route("/admin/{id<\d+>}/edit", methods="GET|POST", name="admin_post_edit")
     *
     * @todo Add Security Voter.
     */
    public function edit(Request $request, Post $post)
    {
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->getDoctrine()->getManager()->flush();

            $this->addFlash('success', 'post.updated_successfully', []);

            return $this->redirectToRoute('admin_post_edit', ['id' => $post->getId()]);
        }

        return $this->render('admin/post/edit.html.twig', [
            'post' => $post,
            'form' => $form->createView(),
        ]);
    }
}
