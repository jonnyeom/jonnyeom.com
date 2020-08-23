<?php

namespace App\Model;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use Spatie\YamlFrontMatter\Document;

class Post
{
    private $body;

    private $title;

    private $description;

    private $date;

    private $tags;

    private $published = TRUE;

    public static function createFromYamlParse(Document $object): Post
    {
        // @Todo: Move to a wrapper service.
        // Obtain a pre-configured Environment with all the CommonMark parsers/renderers ready-to-go
        $environment = Environment::createCommonMarkEnvironment();
        // Add this extension
        $environment->addExtension(new ExternalLinkExtension());
        // Set your configuration
        $config = [
            'external_link' => [
                'internal_hosts' => 'www.jonnyeom.com', // TODO: Don't forget to set this!
                'open_in_new_window' => true,
                'html_class' => 'external-link',
                'nofollow' => '',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ];
        $converter = new CommonMarkConverter($config, $environment);

        $post = new self();
        $post->setTitle($object->title ?? '')
            ->setDescription($object->description ?? '')
            ->setDate($object->date ?? '')
            ->setTags($object->tags ? implode(',', $object->tags) : [])
            ->setBody($converter->convertToHtml($object->body()));

        if ($object->published === 'false') {
            $post->unpublish();
        }

        if ($post->isPublished() && !$post->getTitle()) {
            $post->unpublish();
        }

        return $post;
    }

    /**
     * @return mixed
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param mixed $title
     * @return Post
     */
    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param mixed $description
     * @return Post
     */
    public function setDescription($description)
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * @param mixed $date
     * @return Post
     */
    public function setDate($date)
    {
        $this->date = $date;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * @param mixed $tags
     *
     * @return Post
     */
    public function setTags($tags)
    {
        $this->tags = $tags;
        return $this;
    }

    public function addTag($tag)
    {
        $tags = $this->getTags();
        $tags[] = $tag;

        $this->setTags($tags);
    }

    /**
     * @return mixed
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @param mixed $body
     * @return Post
     */
    public function setBody($body)
    {
        $this->body = $body;
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
    public function publish()
    {
        $this->published = true;
        return $this;
    }

    /**
     * @return Post
     */
    public function unpublish()
    {
        $this->published = false;
        return $this;
    }

}
