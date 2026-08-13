<?php

namespace Tests\Feature;

use Tests\TestCase;

class SeoLandingPageTest extends TestCase
{
    private const BASE_URL = 'https://platform.lonepawn.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', self::BASE_URL);
    }

    public function test_landing_page_contains_search_and_social_metadata(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('<html lang="en-MM"', false)
            ->assertSee('<title>LonePawn | Pawn Shop Management Software for Myanmar</title>', false)
            ->assertSee('rel="canonical" href="' . self::BASE_URL . '/"', false)
            ->assertSee('hreflang="en-MM" href="' . self::BASE_URL . '/"', false)
            ->assertSee('property="og:image" content="' . self::BASE_URL . '/images/landing/lonepawn-social-card.png"', false)
            ->assertSee('name="twitter:card" content="summary_large_image"', false)
            ->assertSee('src="' . self::BASE_URL . '/images/landing/lonepawn-preview.png"', false)
            ->assertDontSee('lh3.googleusercontent.com', false)
            ->assertSeeText('Pawn Shop Management Software for Myanmar SMEs')
            ->assertSeeText('What is LonePawn?')
            ->assertSeeText('Frequently asked questions');
    }

    public function test_structured_data_is_valid_and_matches_visible_faq_content(): void
    {
        $response = $this->get('/');
        $html = $response->getContent();

        $this->assertIsString($html);
        $this->assertSame(1, preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches));

        $structuredData = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $graph = collect($structuredData['@graph'])->keyBy('@type');

        $this->assertSame('https://schema.org', $structuredData['@context']);
        $this->assertSame(self::BASE_URL . '/', $graph->get('WebSite')['url']);
        $this->assertSame('BusinessApplication', $graph->get('SoftwareApplication')['applicationCategory']);
        $this->assertSame('Myanmar', $graph->get('SoftwareApplication')['areaServed']['name']);

        $faqEntities = $graph->get('FAQPage')['mainEntity'];
        $this->assertCount(6, $faqEntities);

        foreach ($faqEntities as $faq) {
            $response->assertSeeText($faq['name']);
            $response->assertSeeText($faq['acceptedAnswer']['text']);
        }
    }

    public function test_robots_policy_allows_answer_engines_and_blocks_training_crawlers(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("User-agent: OAI-SearchBot\nAllow: /", false)
            ->assertSee("User-agent: PerplexityBot\nAllow: /", false)
            ->assertSee("User-agent: GPTBot\nDisallow: /", false)
            ->assertSee("User-agent: CCBot\nDisallow: /", false)
            ->assertSee('Disallow: /dashboard', false)
            ->assertSee('Sitemap: ' . self::BASE_URL . '/sitemap.xml', false);
    }

    public function test_sitemap_contains_only_the_public_canonical_page(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>' . self::BASE_URL . '/</loc>', false)
            ->assertDontSee('/login', false)
            ->assertDontSee('/dashboard', false);

        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml);
        $this->assertCount(1, $xml->url);
    }

    public function test_local_landing_images_have_expected_dimensions(): void
    {
        $dashboardSize = getimagesize(public_path('images/landing/lonepawn-preview.png'));
        $socialSize = getimagesize(public_path('images/landing/lonepawn-social-card.png'));

        $this->assertSame([1340, 629], array_slice($dashboardSize, 0, 2));
        $this->assertSame([1200, 630], array_slice($socialSize, 0, 2));
    }

    public function test_footer_contains_safe_external_powered_by_link(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeText('Powered by')
            ->assertSeeText('1MOREBiT')
            ->assertSee('href="https://1morerbit.tech"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }
}
