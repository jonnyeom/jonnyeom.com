<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Post;
use DateTime;
use DirectoryIterator;
use Psr\Cache\InvalidArgumentException;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function assert;
use function file_get_contents;
use function filemtime;
use function is_string;
use function str_ends_with;
use function strlen;
use function substr;

class BlogContent
{
    public function __construct(private readonly SluggerInterface $slugger)
    {
    }

    /**
     * @return Post[]
     *
     * @throws InvalidArgumentException
     */
    public function getPosts(): array
    {
        $cache = new FilesystemAdapter();
        $posts = [];

        $postsDir = new DirectoryIterator(__DIR__ . '/../Content/Post');
        foreach ($postsDir as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }

            $fileName = $fileinfo->getFilename();
            if (! str_ends_with($fileName, '.md')) {
                continue;
            }

            $fileName = substr($fileName, 0, strlen($fileName) - 3);
            $cid      = 'posts_' . $fileName;

            $post = $cache->get($cid, function (ItemInterface $item) use ($fileName) {
                // @Todo Parse Yaml as part of Markdown Converter.
                $postContent = file_get_contents(__DIR__ . '/../Content/Post/' . $fileName . '.md');
                assert(is_string($postContent));
                $object     = YamlFrontMatter::parse($postContent);
                $parsedPost = Post::createFromYamlParse($object);

                // @Todo Move this to the Markdown Converter.
                // Set the Last Updated as the Last Modified time.
                if ($parsedPost->getLastUpdated()) {
                    $lastUpdated = filemtime(__DIR__ . '/../Content/Post/' . $fileName . '.md');
                    if ($lastUpdated) {
                        $parsedPost->setLastUpdated((new DateTime())->setTimestamp($lastUpdated));
                    } else {
                        $parsedPost->setLastUpdated($parsedPost->getDate());
                    }
                }

                if (! $parsedPost->getSlug()) {
                    $parsedPost->setSlug($this->slugger->slug($parsedPost->getTitle()));
                }

                return $parsedPost;
            });

            assert($post instanceof Post);

            $posts[$post->getSlug()] = $post;
        }

        return $posts;
    }

    /** @throws InvalidArgumentException */
    public function getPost(string $slug): Post|null
    {
        $posts = $this->getPosts();

        return $posts[$slug] ?? null;
    }
}
