<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SeoTagsTest extends WebTestCase
{
    public function testDefaultDescriptionAppearsWhenNotOverridden(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/experiments');

        $description = $crawler->filter('meta[name="description"]')->attr('content');
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('professional developer', $description);
    }

    public function testDefaultTitleAppearsWhenNotOverridden(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/experiments');

        $this->assertSame('jonnyeom | Jonathan Eom', $crawler->filter('title')->text());
    }

    public function testPageTitleOverridesDefault(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertSame('jonnyeom | Home', $crawler->filter('title')->text());
    }

    public function testOgImageDefaultIsPresent(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/');

        $ogImage = $crawler->filter('meta[property="og:image"]')->attr('content');
        $this->assertSame('https://www.jonnyeom.com/images/jonnyeom.jpg', $ogImage);
    }

    public function testOgTitleMatchesPageTitle(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/about');

        $this->assertSame('jonnyeom | About', $crawler->filter('title')->text());
        $this->assertSame('jonnyeom | About', $crawler->filter('meta[property="og:title"]')->attr('content'));
    }

    public function testBlogPostSetsDescriptionAndKeywords(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/writing/example-script-to-run-phpcs-on-only-changed-files');

        $description = $crawler->filter('meta[name="description"]')->attr('content');
        $this->assertStringContainsString('PHP CodeSniffer', $description);

        $keywords = $crawler->filter('meta[name="keywords"]')->attr('content');
        $this->assertStringContainsString('code-example', $keywords);
        $this->assertStringContainsString('ci', $keywords);
    }

    public function testBlogPostTitleOverridesDefault(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/writing/example-script-to-run-phpcs-on-only-changed-files');

        $this->assertStringContainsString('PHP CodeSniffer', $crawler->filter('title')->text());
        $this->assertStringContainsString('jonnyeom', $crawler->filter('title')->text());
    }
}
