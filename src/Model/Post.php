<?php

namespace App\Model;

use App\CommonMark\Block\Parser\CustomHeadingParser;
use DateTime;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use Spatie\CommonMarkHighlighter\FencedCodeRenderer;
use Spatie\CommonMarkHighlighter\IndentedCodeRenderer;
use Spatie\YamlFrontMatter\Document;

class Post
{
    private string $body;

    private string $title;

    private ?string $description;

    private DateTime $date;

    private array $tags = [];

    private bool $published = TRUE;

    private ?string $slug = NULL;

    public static function createFromYamlParse(Document $object): Post
    {
        // @Todo: Move to a wrapper service.
        // Set your configuration.
        $config = [
            'external_link' => [
                'internal_hosts' => 'www.jonnyeom.com',
                'open_in_new_window' => true,
                'html_class' => 'external-link',
                'nofollow' => '',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
            'heading_permalink' => [
                'html_class' => 'heading-permalink',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'after',
                'min_heading_level' => 1,
                'max_heading_level' => 6,
                'title' => 'Permalink',
                'symbol' => '#',
            ],
        ];

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new ExternalLinkExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addBlockStartParser(new CustomHeadingParser(), 61);
        $environment->addRenderer(FencedCode::class, new FencedCodeRenderer(['html', 'php', 'js', 'json', 'shell', 'yaml']), 1);
        $environment->addRenderer(IndentedCode::class, new IndentedCodeRenderer(['html', 'php', 'js', 'json', 'shell', 'yaml']), 1);
        $converter = new MarkdownConverter($environment);

        $post = new self();
        $post->setTitle($object->title ?? '')
            ->setDescription($object->description ?? '')
            ->setDate(new DateTime($object->date ?? 'now'))
            ->setTags($object->tags ?? [])
            ->setBody($converter->convertToHtml($object->body()));

        if ($object->slug) {
            $post->setSlug($object->slug);
        }

        if ($object->published === false) {
            $post->unpublish();
        }
        // Do not publish if it does not have a title.
        if ($post->isPublished() && !$post->getTitle()) {
            $post->unpublish();
        }

        return $post;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title
     * @return Post
     */
    public function setTitle(string $title): Post
    {
        $this->title = $title;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string $description
     * @return Post
     */
    public function setDescription(string $description): Post
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return DateTime
     */
    public function getDate(): DateTime
    {
        return $this->date;
    }

    /**
     * @param DateTime $date
     * @return Post
     */
    public function setDate(DateTime $date)
    {
        $this->date = $date;
        return $this;
    }

    /**
     * @return array
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array $tags
     * @return Post
     */
    public function setTags(array $tags): Post
    {
        $this->tags = $tags;
        return $this;
    }

    public function addTag(string $tag): Post
    {
        $tags = $this->getTags();
        $tags[] = $tag;

        return $this->setTags($tags);
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @param string $body
     * @return Post
     */
    public function setBody(string $body): Post
    {
        $this->body = $body;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSlug(): ?string
    {
        return $this->slug;
    }

    /**
     * @param string $slug
     * @return Post
     */
    public function setSlug(string $slug): Post
    {
        $this->slug = $slug;
        return $this;
    }

    /**
     * @return bool
     */
    public function isPublished(): bool
    {
        return $this->published;
    }

    /**
     * @return Post
     */
    public function publish(): Post
    {
        $this->published = true;
        return $this;
    }

    /**
     * @return Post
     */
    public function unpublish(): Post
    {
        $this->published = false;
        return $this;
    }

}
