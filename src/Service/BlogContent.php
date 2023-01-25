<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Post;
use DateTime;
use DirectoryIterator;
use Psr\Cache\InvalidArgumentException;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

use function assert;
use function file_get_contents;
use function filemtime;
use function strlen;
use function substr;

class BlogContent
{
    private SluggerInterface $slugger;

    public function __construct(private AdapterInterface $cache)
    {
    }

    /**
     * @return Post[]
     *
     * @throws InvalidArgumentException
     */
    public function getPosts(): array
    {
        $posts = [];

        $postsDir = new DirectoryIterator(__DIR__ . '/../Content/Post');
        foreach ($postsDir as $fileinfo) {
            if (!$fileinfo->isDot()) {
                $fileName = $fileinfo->getFilename();
                if (substr($fileName, -3) !== '.md') {
                    continue;
                }

                $fileName = substr($fileName, 0, strlen($fileName)-3);
                $cid = 'posts_'.$fileName;

                $item = $this->cache->getItem($cid);
                if (!$item->isHit()) {
                    // @Todo Parse Yaml as part of Markdown Converter.
                    $object = YamlFrontMatter::parse(file_get_contents(__DIR__."/../Content/Post/{$fileName}.md"));
                    $post = Post::createFromYamlParse($object);

                    // @Todo Move this to the Markdown Converter.
                    // Set the Last Updated as the Last Modified time.
                    if ($post->getLastUpdated()) {
                        $lastUpdated = filemtime(__DIR__."/../Content/Post/{$fileName}.md");
                        $post->setLastUpdated((new DateTime())->setTimestamp($lastUpdated));
                    }

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

    /** @throws InvalidArgumentException */
    public function getPost(string $slug): Post|null
    {
        $posts = $this->getPosts();

        return $posts[$slug] ?? null;
    }

    #[Required]
    public function setSlugger(SluggerInterface $slugger): void
    {
        $this->slugger = $slugger;
    }
}
