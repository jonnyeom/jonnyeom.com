<?php

namespace App\Service;

use App\Model\Post;
use DirectoryIterator;
use Psr\Cache\InvalidArgumentException;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class BlogContent
{
    /**
     * @var AdapterInterface
     */
    private $cache;

    private SluggerInterface $slugger;

    public function __construct(AdapterInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @return Post[]
     *
     * @throws InvalidArgumentException
     */
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

                    if (!$post->getSlug()) {
                        $post->setSlug($this->slugger->slug($post->getTitle()));
                    }

                    $item->set($post);
                    $this->cache->save($item);
                }

                /** @var Post $post */
                $post = $item->get();

                $posts[$post->getSlug()] = $post;
            }
        }

        return $posts;
    }

    /**
     * @param $slug
     *
     * @return Post|null
     *
     * @throws InvalidArgumentException
     */
    public function getPost($slug): ?Post
    {
        $posts = $this->getPosts();

        return $posts[$slug] ?? null;
    }

    /**
     * @required
     */
    public function setSlugger(SluggerInterface $slugger): void
    {
        $this->slugger = $slugger;
    }
}
