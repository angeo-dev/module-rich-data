<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\JsonLd\Renderer;

use Angeo\RichData\Api\Data\SchemaInterface;
use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaRendererTest extends TestCase
{
    private LoggerInterface|MockObject $logger;
    private StoreInterface|MockObject  $store;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->store  = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
    }

    private function mockBuilder(string $type, array $schema, bool $enabled = true): SchemaInterface|MockObject
    {
        $builder = $this->createMock(SchemaInterface::class);
        $builder->method('getType')->willReturn($type);
        $builder->method('isEnabled')->willReturn($enabled);
        $builder->method('build')->willReturn($schema);
        return $builder;
    }

    public function testRenderReturnsEmptyStringWithNoBuilders(): void
    {
        $renderer = new SchemaRenderer($this->logger, []);
        $this->assertSame('', $renderer->render($this->store));
    }

    public function testRenderProducesScriptTag(): void
    {
        $builder = $this->mockBuilder('product', [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => 'Test Product',
        ]);

        $renderer = new SchemaRenderer($this->logger, [$builder]);
        $output   = $renderer->render($this->store, ['page_type' => 'catalog_product_view']);

        $this->assertStringContainsString('<script type="application/ld+json">', $output);
        $this->assertStringContainsString('</script>', $output);
        $this->assertStringContainsString('"@type": "Product"', $output);
    }

    public function testRenderSkipsDisabledBuilders(): void
    {
        $disabledBuilder = $this->mockBuilder('product', ['@type' => 'Product', 'name' => 'X'], false);
        $enabledBuilder  = $this->mockBuilder('organization', ['@type' => 'Organization', 'name' => 'Y'], true);

        $renderer = new SchemaRenderer($this->logger, [$disabledBuilder, $enabledBuilder]);
        $output   = $renderer->render($this->store);

        $this->assertStringNotContainsString('"Product"', $output);
        $this->assertStringContainsString('"Organization"', $output);
    }

    public function testRenderSkipsNullOutput(): void
    {
        $builder = $this->createMock(SchemaInterface::class);
        $builder->method('isEnabled')->willReturn(true);
        $builder->method('build')->willReturn(null);

        $renderer = new SchemaRenderer($this->logger, [$builder]);
        $this->assertSame('', $renderer->render($this->store));
    }

    public function testRenderSkipsEmptyArrayOutput(): void
    {
        $builder = $this->mockBuilder('website', []);

        $renderer = new SchemaRenderer($this->logger, [$builder]);
        $this->assertSame('', $renderer->render($this->store));
    }

    public function testRenderConcatenatesMultipleBuilders(): void
    {
        $b1 = $this->mockBuilder('product',      ['@type' => 'Product',      '@context' => 'https://schema.org']);
        $b2 = $this->mockBuilder('organization', ['@type' => 'Organization', '@context' => 'https://schema.org']);

        $renderer = new SchemaRenderer($this->logger, [$b1, $b2]);
        $output   = $renderer->render($this->store);

        $this->assertStringContainsString('"Product"', $output);
        $this->assertStringContainsString('"Organization"', $output);

        // Two separate script blocks
        $this->assertSame(2, substr_count($output, '<script type="application/ld+json">'));
    }

    public function testRenderLogsErrorAndContinuesOnBuilderException(): void
    {
        $failingBuilder = $this->createMock(SchemaInterface::class);
        $failingBuilder->method('isEnabled')->willReturn(true);
        $failingBuilder->method('build')->willThrowException(new \RuntimeException('DB error'));

        $goodBuilder = $this->mockBuilder('organization', ['@type' => 'Organization', '@context' => 'https://schema.org']);

        $this->logger->expects($this->once())->method('error');

        $renderer = new SchemaRenderer($this->logger, [$failingBuilder, $goodBuilder]);
        $output   = $renderer->render($this->store);

        // Good builder still ran
        $this->assertStringContainsString('"Organization"', $output);
    }

    public function testRenderTypeReturnsCorrectBuilder(): void
    {
        $orgBuilder  = $this->mockBuilder('organization', ['@type' => 'Organization', '@context' => 'https://schema.org']);
        $prodBuilder = $this->mockBuilder('product',      ['@type' => 'Product',      '@context' => 'https://schema.org']);

        $renderer = new SchemaRenderer($this->logger, [$orgBuilder, $prodBuilder]);
        $output   = $renderer->renderType('product', $this->store);

        $this->assertStringContainsString('"Product"', $output);
        $this->assertStringNotContainsString('"Organization"', $output);
    }

    public function testRenderTypeReturnsEmptyForUnknownType(): void
    {
        $renderer = new SchemaRenderer($this->logger, []);
        $this->assertSame('', $renderer->renderType('nonexistent', $this->store));
    }

    public function testOutputIsValidJson(): void
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => 'Widget with "quotes" & <special> chars',
            'offers'   => ['@type' => 'Offer', 'price' => '19.99'],
        ];

        $builder  = $this->mockBuilder('product', $schema);
        $renderer = new SchemaRenderer($this->logger, [$builder]);
        $output   = $renderer->render($this->store);

        preg_match('/<script[^>]+>(.*?)<\/script>/si', $output, $matches);
        $decoded = json_decode(trim($matches[1]), true);

        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertSame('Product', $decoded['@type']);
    }
}
