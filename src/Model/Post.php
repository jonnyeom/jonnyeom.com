<?php

declare(strict_types=1);

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
use Stringable;

class Post
{
    private string $body;

    private string $title;

    private string|null $description = null;

    private DateTime $date;

    /** @var string[] $tags */
    private array $tags = [];

    private bool $published = true;

    private string|null $slug = null;

    private DateTime|null $lastUpdated = null;

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
        $highlightLanguages = ['html', 'php', 'js', 'json', 'bash', 'yaml', 'twig'];
        $environment->addRenderer(FencedCode::class, new FencedCodeRenderer($highlightLanguages), 1);
        $environment->addRenderer(IndentedCode::class, new IndentedCodeRenderer($highlightLanguages), 1);
        $converter = new MarkdownConverter($environment);

        $post = new self();
        $post->setTitle($object->title ?? '')
            ->setDescription($object->description ?? '')
            ->setDate(new DateTime($object->date ?? 'now'))
            ->setTags($object->tags ?? [])
            ->setBody($converter->convert($object->body()));

        if ($object->__get('slug')) {
            $post->setSlug($object->__get('slug'));
        }

        if ($object->__get('published') === false) {
            $post->unpublish();
        }

        // Do not publish if it does not have a title.
        if ($post->isPublished() && ! $post->getTitle()) {
            $post->unpublish();
        }

        if ($object->matter('last-updated')) {
            $post->setLastUpdated(new DateTime('now'));
        }

        return $post;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): Post
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): string|null
    {
        return $this->description;
    }

    public function setDescription(string $description): Post
    {
        $this->description = $description;

        return $this;
    }

    public function getDate(): DateTime
    {
        return $this->date;
    }

    public function setDate(DateTime $date): Post
    {
        $this->date = $date;

        return $this;
    }

    /** @return string[] */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param string[] $tags */
    public function setTags(array $tags): Post
    {
        $this->tags = $tags;

        return $this;
    }

    public function addTag(string $tag): Post
    {
        $tags   = $this->getTags();
        $tags[] = $tag;

        return $this->setTags($tags);
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string|Stringable $body): Post
    {
        $this->body = (string) $body;

        return $this;
    }

    public function getSlug(): string|null
    {
        return $this->slug;
    }

    public function setSlug(string|Stringable $slug): Post
    {
        $this->slug = (string) $slug;

        return $this;
    }

    public function getLastUpdated(): DateTime|null
    {
        return $this->lastUpdated;
    }

    public function setLastUpdated(DateTime $lastUpdated): Post
    {
        $this->lastUpdated = $lastUpdated;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function publish(): Post
    {
        $this->published = true;

        return $this;
    }

    public function unpublish(): Post
    {
        $this->published = false;

        return $this;
    }
}
