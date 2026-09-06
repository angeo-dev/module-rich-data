<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\Builder\BreadcrumbBuilder;
use Angeo\RichData\Model\JsonLd\IdFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BreadcrumbBuilderTest extends TestCase
{
    private ScopeConfigInterface|MockObject $scopeConfig;
    private StoreInterface|MockObject $store;
    private BreadcrumbBuilder $builder;

    /** @var array<string, bool> */
    private array $flags = [];

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->store       = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getBaseUrl')->willReturn('https://shop.test/');

        $this->flags = [
            'angeo_rich_data/general/enabled'              => true,
            'angeo_rich_data/breadcrumb/enabled'           => true,
            'angeo_rich_data/product/include_breadcrumb'   => true,
        ];

        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(fn (string $path) => $this->flags[$path] ?? false);

        $this->builder = new BreadcrumbBuilder($this->scopeConfig, new IdFactory());
    }

    public function testTrailIsNumberedAndTheLastCrumbHasNoItemUrl(): void
    {
        $schema = $this->builder->build($this->store, [
            'page_url'    => 'https://shop.test/jackets/alpine.html',
            'breadcrumbs' => [
                ['name' => 'Home', 'url' => 'https://shop.test'],
                ['name' => 'Jackets', 'url' => 'https://shop.test/jackets.html'],
                ['name' => 'Alpine Jacket', 'url' => ''],
            ],
        ]);

        $this->assertSame('BreadcrumbList', $schema['@type']);
        $this->assertSame('https://shop.test/jackets/alpine.html#breadcrumb', $schema['@id']);
        $this->assertCount(3, $schema['itemListElement']);
        $this->assertSame([1, 2, 3], array_column($schema['itemListElement'], 'position'));
        $this->assertArrayNotHasKey('item', $schema['itemListElement'][2]);
    }

    public function testASingleCrumbIsNotWorthPublishing(): void
    {
        $schema = $this->builder->build($this->store, [
            'breadcrumbs' => [['name' => 'Home', 'url' => 'https://shop.test']],
        ]);

        $this->assertNull($schema);
    }

    public function testEmptyContextProducesNothing(): void
    {
        $this->assertNull($this->builder->build($this->store, []));
    }

    /**
     * A store that switched breadcrumbs off in 1.x must not get them back after
     * the upgrade just because the new group defaults to on.
     */
    public function testLegacyFlagStillVetoesTheNewGroup(): void
    {
        $this->flags['angeo_rich_data/product/include_breadcrumb'] = false;

        $this->assertFalse($this->builder->isEnabled($this->store));
    }

    public function testEnabledWhenBothFlagsAreOn(): void
    {
        $this->assertTrue($this->builder->isEnabled($this->store));
    }
}
