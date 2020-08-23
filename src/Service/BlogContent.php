<?php

namespace App\Service;

use App\Model\Post;
use DirectoryIterator;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Cache\Adapter\AdapterInterface;

class BlogContent
{
    /**
     * @var AdapterInterface
     */
    private $cache;

    public function __construct(AdapterInterface $cache)
    {
        $this->cache = $cache;
    }
    
    public function getPosts(): array
    {
        $posts = [];

        $postsDir = new DirectoryIterator(__DIR__ . "/../Content/Post");
        foreach ($postsDir as $fileinfo) {
            if (!$fileinfo->isDot()) {
                $fileName = $fileinfo->getFilename();
                if (substr($fileName, -3) !== '.md') {
                    continue;
                }

                $fileName = substr($fileName, 0, strlen($fileName)-3);
                $cid = 'posts_' . $fileName;

                $item = $this->cache->getItem($cid);
                if (!$item->isHit()) {
                    $object = YamlFrontMatter::parse(file_get_contents(__DIR__ . "/../Content/Post/{$fileName}.md"));
                    $post = Post::createFromYamlParse($object);

                    $item->set($post);
                    $this->cache->save($item);
                }

                /** @var Post $post */
                $post = $item->get();

                $posts[$fileName] = $post;
            }
        }

        return $posts;
    }

    public function getPost($slug)
    {

    }
}