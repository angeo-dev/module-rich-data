<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\JsonLd\Renderer;

use Angeo\RichData\Api\Data\SchemaInterface;
use Angeo\RichData\Model\Config\Source\OutputMode;
use Angeo\RichData\Model\JsonLd\JsonEncoder;
use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaRendererTest extends TestCase
{
    private LoggerInterface|MockObject $logger;
    private ScopeConfigInterface|MockObject $scopeConfig;
    private StoreInterface|MockObject $store;

    protected function setUp(): void
    {
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->store       = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
    }

    public function testGraphModeMergesEveryNodeIntoOneScriptTag(): void
    {
        $this->useMode(OutputMode::MODE_GRAPH);

        $renderer = $this->createRenderer([
            $this->builder('organization', ['@context' => 'https://schema.org', '@type' => 'Organization']),
            $this->builder('product', ['@context' => 'https://schema.org', '@type' => 'Product']),
        ]);

        $html = $renderer->render($this->store);

        $this->assertSame(1, substr_count($html, '<script type="application/ld+json">'));

        $payload = $this->extractJson($html)[0];
        $this->assertSame('https://schema.org', $payload['@context']);
        $this->assertCount(2, $payload['@graph']);
        $this->assertArrayNotHasKey('@context', $payload['@graph'][0], 'inner nodes must not repeat @context');
    }

    public function testLegacyModeKeepsOneScriptTagPerBuilder(): void
    {
        $this->useMode(OutputMode::MODE_LEGACY);

        $renderer = $this->createRenderer([
            $this->builder('organization', ['@type' => 'Organization']),
            $this->builder('product', ['@type' => 'Product']),
        ]);

        $html = $renderer->render($this->store);

        $this->assertSame(2, substr_count($html, '<script type="application/ld+json">'));

        foreach ($this->extractJson($html) as $payload) {
            $this->assertSame('https://schema.org', $payload['@context']);
        }
    }

    public function testDisabledBuilderIsSkipped(): void
    {
        $this->useMode(OutputMode::MODE_GRAPH);

        $renderer = $this->createRenderer([
            $this->builder('product', ['@type' => 'Product'], false),
        ]);

        $this->assertSame('', $renderer->render($this->store));
    }

    public function testFailingBuilderIsLoggedAndDoesNotStopTheOthers(): void
    {
        $this->useMode(OutputMode::MODE_GRAPH);

        $broken = $this->createMock(SchemaInterface::class);
        $broken->method('getType')->willReturn('broken');
        $broken->method('isEnabled')->willReturn(true);
        $broken->method('build')->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())->method('error');

        $renderer = $this->createRenderer([
            $broken,
            $this->builder('product', ['@type' => 'Product']),
        ]);

        $payload = $this->extractJson($renderer->render($this->store))[0];

        $this->assertCount(1, $payload['@graph']);
        $this->assertSame('Product', $payload['@graph'][0]['@type']);
    }

    public function testCollectReturnsRawNodes(): void
    {
        $this->useMode(OutputMode::MODE_GRAPH);

        $renderer = $this->createRenderer([$this->builder('product', ['@type' => 'Product'])]);

        $this->assertSame([['@type' => 'Product']], $renderer->collect($this->store));
    }

    private function useMode(string $mode): void
    {
        $this->scopeConfig->method('getValue')->willReturn($mode);
    }

    /** @param SchemaInterface[] $builders */
    private function createRenderer(array $builders): SchemaRenderer
    {
        return new SchemaRenderer($this->logger, new JsonEncoder(false), $this->scopeConfig, $builders);
    }

    private function builder(string $type, array $schema, bool $enabled = true): SchemaInterface
    {
        $builder = $this->createMock(SchemaInterface::class);
        $builder->method('getType')->willReturn($type);
        $builder->method('isEnabled')->willReturn($enabled);
        $builder->method('build')->willReturn($schema);

        return $builder;
    }

    /** @return array<int, array> */
    private function extractJson(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">\s*(.*?)\s*</script>#s', $html, $matches);

        return array_map(static fn ($json) => json_decode($json, true), $matches[1]);
    }
}
