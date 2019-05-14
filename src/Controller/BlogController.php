<?php

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Routing\Annotation\Route;

class BlogController extends BaseController
{
    /**
     * @Route("/blog", name="app_blog")
     */
    public function blog()
    {
        return $this->inProgress('Blog');
    }

    /**
     * @Route("/blog/post/example", name="app_post_example")
     */
    public function post(AdapterInterface $cache)
    {
        $item = $cache->getItem('markdown_post_homepage');
        if (!$item->isHit()) {
            $converter = new CommonMarkConverter();
            $markdown = file_get_contents(__DIR__ . '/../Content/Post/Example.md');
            $item->set($converter->convertToHtml($markdown));
            $cache->save($item);
        }
        $content = $item->get();

        return $this->render('blog/post.html.twig', [
            'short_title' => 'Example',
            'content' => $content,
        ]);
    }

}
