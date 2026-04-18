<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\Builder\FaqBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FaqBuilderTest extends TestCase
{
    private ScopeConfigInterface|MockObject $scopeConfig;
    private StoreInterface|MockObject       $store;
    private FaqBuilder                      $builder;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('isSetFlag')->willReturn(true);

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);

        $this->builder = new FaqBuilder($this->scopeConfig);
    }

    public function testGetType(): void
    {
        $this->assertSame('faq', $this->builder->getType());
    }

    public function testReturnsNullWithNoContent(): void
    {
        $result = $this->builder->build($this->store, []);
        $this->assertNull($result);
    }

    public function testReturnsNullWhenNoPairsFound(): void
    {
        $html = '<div><p>Just some text with no questions.</p></div>';
        $result = $this->builder->build($this->store, ['cms_page_content' => $html]);
        $this->assertNull($result);
    }

    public function testParsesExplicitDataAttributes(): void
    {
        $html = '<div data-faq-question="What is your return policy?" data-faq-answer="We offer 30-day returns on all items."></div>';

        $result = $this->builder->build($this->store, ['cms_page_content' => $html]);

        $this->assertNotNull($result);
        $this->assertSame('FAQPage', $result['@type']);
        $this->assertCount(1, $result['mainEntity']);
        $this->assertSame('What is your return policy?', $result['mainEntity'][0]['name']);
        $this->assertSame('Answer', $result['mainEntity'][0]['acceptedAnswer']['@type']);
        $this->assertSame('We offer 30-day returns on all items.', $result['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testParsesMultipleExplicitPairs(): void
    {
        $html = '
            <div data-faq-question="Do you ship internationally?" data-faq-answer="Yes, we ship to over 50 countries worldwide."></div>
            <div data-faq-question="How long does delivery take?" data-faq-answer="Standard delivery takes 5-7 business days."></div>
        ';

        $result = $this->builder->build($this->store, ['cms_page_content' => $html]);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['mainEntity']);
    }

    public function testParsesHeuristicH2ParagraphPairs(): void
    {
        $html = '
            <h2>What is your return policy?</h2>
            <p>We offer a 30-day money-back guarantee on all products purchased from our store.</p>
            <h2>Do you offer free shipping?</h2>
            <p>Yes, we offer free shipping on all orders over $50 within the continental United States.</p>
        ';

        $result = $this->builder->build($this->store, ['cms_page_content' => $html]);

        $this->assertNotNull($result);
        $this->assertSame('FAQPage', $result['@type']);
        $this->assertCount(2, $result['mainEntity']);

        $questions = array_column($result['mainEntity'], 'name');
        $this->assertContains('What is your return policy?', $questions);
        $this->assertContains('Do you offer free shipping?', $questions);
    }

    public function testHeuristicSkipsShortAnswers(): void
    {
        $html = '
            <h2>Valid question with enough content here?</h2>
            <p>This is a sufficiently long answer that meets the minimum length requirement.</p>
            <h2>Question with short answer?</h2>
            <p>Short.</p>
        ';

        $result = $this->builder->build($this->store, ['cms_page_content' => $html]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['mainEntity']); // only 1 valid pair
    }

    public function testExplicitPairsTakePriorityOverHeuristic(): void
    {
        $html = '
            <div data-faq-question="Explicit Q?" data-faq-answer="Explicit answer that is long enough."></div>
            <h2>Heuristic heading?</h2>
            <p>Heuristic paragraph answer that is long enough to be valid.</p>
        ';

        $result = $this->builder->build($this->store, ['cms_page_content' => $html]);

        $this->assertNotNull($result);
        // Should use explicit, not heuristic
        $this->assertCount(1, $result['mainEntity']);
        $this->assertSame('Explicit Q?', $result['mainEntity'][0]['name']);
    }

    public function testOutputHasCorrectContext(): void
    {
        $html = '<div data-faq-question="Is this valid schema?" data-faq-answer="Yes, this is completely valid schema markup."></div>';

        $result = $this->builder->build($this->store, ['cms_page_content' => $html]);

        $this->assertSame('https://schema.org', $result['@context']);
        $this->assertSame('FAQPage', $result['@type']);
        $this->assertSame('Question', $result['mainEntity'][0]['@type']);
    }
}
