<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\Builder\FaqBuilder;
use Angeo\RichData\Model\JsonLd\IdFactory;
use Angeo\RichData\Model\Page\FaqPageMatcher;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FaqBuilderTest extends TestCase
{
    private ScopeConfigInterface|MockObject $scopeConfig;
    private StoreInterface|MockObject $store;
    private FaqBuilder $builder;

    /** @var array<string, string> */
    private array $config = [];

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->store       = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getBaseUrl')->willReturn('https://shop.test/');

        $this->config = ['angeo_rich_data/faq/cms_identifiers' => ''];

        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(fn (string $path) => $this->config[$path] ?? '');

        $this->builder = new FaqBuilder($this->scopeConfig, new FaqPageMatcher(), new IdFactory());
    }

    public function testExplicitAttributesArePreferred(): void
    {
        $html = '<div data-faq-question="What is your return policy?" '
            . 'data-faq-answer="We offer 30-day returns on all items."></div>'
            . '<h2>Ignored heading here</h2><p>This paragraph should not be picked up at all.</p>';

        $schema = $this->build($html, 'faq');

        $this->assertCount(1, $schema['mainEntity']);
        $this->assertSame('What is your return policy?', $schema['mainEntity'][0]['name']);
    }

    public function testHeuristicPairsAreUsedAsFallback(): void
    {
        $html = '<h2>How long does shipping take?</h2><p>Orders ship within two business days.</p>'
            . '<h3>Do you ship abroad?</h3><p>Yes, we ship across the European Union.</p>';

        $schema = $this->build($html, 'faq');

        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertCount(2, $schema['mainEntity']);
        $this->assertSame('Question', $schema['mainEntity'][0]['@type']);
    }

    /**
     * The main behaviour change in 2.0.0: a CMS page that is not an FAQ page
     * publishes nothing, no matter what its markup looks like.
     */
    public function testUnrelatedCmsPagePublishesNothing(): void
    {
        $html = '<h2>Our long and proud history</h2><p>We have been trading since 1998 in this town.</p>';

        $this->assertNull($this->build($html, 'about-us'));
    }

    public function testExplicitIdentifierListAllowsANonFaqSlug(): void
    {
        $this->config['angeo_rich_data/faq/cms_identifiers'] = 'help-centre, returns';

        $html = '<h2>How long does shipping take?</h2><p>Orders ship within two business days.</p>';

        $this->assertNotNull($this->build($html, 'help-centre'));
        $this->assertNull($this->build($html, 'about-us'));
    }

    public function testShortCyrillicQuestionsAreMeasuredInCharactersNotBytes(): void
    {
        // Nine characters: below the ten-character minimum, but eighteen bytes,
        // which is what 1.x counted.
        $html = '<h2>Чи є ггг</h2><p>Так, ми доставляємо по всій Європі щодня.</p>';

        $this->assertNull($this->build($html, 'faq'));
    }

    public function testEmptyContentProducesNothing(): void
    {
        $this->assertNull($this->build('', 'faq'));
    }

    private function build(string $html, string $identifier): ?array
    {
        return $this->builder->build($this->store, [
            'cms_page_content'    => $html,
            'cms_page_identifier' => $identifier,
            'page_url'            => 'https://shop.test/' . $identifier,
        ]);
    }
}
