<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\Builder\ProductBuilder;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductBuilderTest extends TestCase
{
    private ScopeConfigInterface|MockObject  $scopeConfig;
    private StockRegistryInterface|MockObject $stockRegistry;
    private ImageHelper|MockObject            $imageHelper;
    private PriceCurrencyInterface|MockObject $priceCurrency;
    private ReviewFactory|MockObject          $reviewFactory;
    private StoreInterface|MockObject         $store;
    private Product|MockObject                $product;
    private ProductBuilder                    $builder;

    protected function setUp(): void
    {
        $this->scopeConfig   = $this->createMock(ScopeConfigInterface::class);
        $this->stockRegistry = $this->createMock(StockRegistryInterface::class);
        $this->imageHelper   = $this->createMock(ImageHelper::class);
        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $this->reviewFactory = $this->createMock(ReviewFactory::class);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $this->imageHelper->method('init')->willReturnSelf();
        $this->imageHelper->method('setImageFile')->willReturnSelf();
        $this->imageHelper->method('getUrl')->willReturn('https://example.com/image.jpg');

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getName')->willReturn('Test Store');
        $this->store->method('getCurrentCurrencyCode')->willReturn('USD');

        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['angeo_rich_data/product/availability_in_stock',    'store', 1, 'https://schema.org/InStock'],
            ['angeo_rich_data/product/availability_out_of_stock','store', 1, 'https://schema.org/OutOfStock'],
            ['angeo_rich_data/product/condition',                'store', 1, 'NewCondition'],
            ['angeo_rich_data/product/brand_attribute',          'store', 1, 'manufacturer'],
        ]);

        $this->product = $this->createMock(Product::class);
        $this->product->method('getId')->willReturn(42);
        $this->product->method('getName')->willReturn('Alpine Hiking Jacket');
        $this->product->method('getSku')->willReturn('WB-004');
        $this->product->method('getProductUrl')->willReturn('https://example.com/alpine-jacket');
        $this->product->method('getFinalPrice')->willReturn(189.99);
        $this->product->method('getShortDescription')->willReturn('Waterproof 3-layer shell for alpine conditions.');
        $this->product->method('getDescription')->willReturn('Full description here.');
        $this->product->method('getImage')->willReturn('/w/b/wb-004.jpg');

        $review = $this->createMock(\Magento\Review\Model\Review::class);
        $this->reviewFactory->method('create')->willReturn($review);

        $this->builder = new ProductBuilder(
            $this->scopeConfig,
            $this->stockRegistry,
            $this->imageHelper,
            $this->priceCurrency,
            $this->reviewFactory,
        );
    }

    public function testGetType(): void
    {
        $this->assertSame('product', $this->builder->getType());
    }

    public function testBuildReturnsNullWithoutProduct(): void
    {
        $result = $this->builder->build($this->store, []);
        $this->assertNull($result);
    }

    public function testBuildReturnsProductSchema(): void
    {
        $result = $this->builder->build($this->store, ['product' => $this->product]);

        $this->assertIsArray($result);
        $this->assertSame('https://schema.org', $result['@context']);
        $this->assertSame('Product', $result['@type']);
        $this->assertSame('Alpine Hiking Jacket', $result['name']);
        $this->assertSame('https://example.com/alpine-jacket', $result['url']);
    }

    public function testBuildIncludesOffers(): void
    {
        $result = $this->builder->build($this->store, ['product' => $this->product]);

        $this->assertArrayHasKey('offers', $result);
        $offers = $result['offers'];
        $this->assertSame('Offer', $offers['@type']);
        $this->assertSame('189.99', $offers['price']);
        $this->assertSame('USD', $offers['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $offers['availability']);
    }

    public function testBuildIncludesSku(): void
    {
        $result = $this->builder->build($this->store, ['product' => $this->product]);
        $this->assertArrayHasKey('sku', $result);
        $this->assertSame('WB-004', $result['sku']);
    }

    public function testBuildIncludesDescription(): void
    {
        $result = $this->builder->build($this->store, ['product' => $this->product]);
        $this->assertArrayHasKey('description', $result);
        $this->assertStringContainsString('Waterproof', $result['description']);
    }

    public function testBuildIncludesImage(): void
    {
        $result = $this->builder->build($this->store, ['product' => $this->product]);
        $this->assertArrayHasKey('image', $result);
        $this->assertSame('https://example.com/image.jpg', $result['image']);
    }

    public function testBuildIncludesConditionInOffers(): void
    {
        $result = $this->builder->build($this->store, ['product' => $this->product]);
        $this->assertArrayHasKey('itemCondition', $result['offers']);
        $this->assertStringContainsString('NewCondition', $result['offers']['itemCondition']);
    }

    public function testBuildIncludesSellerInOffers(): void
    {
        $result = $this->builder->build($this->store, ['product' => $this->product]);
        $this->assertArrayHasKey('seller', $result['offers']);
        $this->assertSame('Organization', $result['offers']['seller']['@type']);
        $this->assertSame('Test Store', $result['offers']['seller']['name']);
    }

    public function testOutOfStockProductUsesOutOfStockAvailability(): void
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(false);
        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $result = $this->builder->build($this->store, ['product' => $this->product]);

        $this->assertSame('https://schema.org/OutOfStock', $result['offers']['availability']);
    }

    public function testIsEnabledReturnsTrueWhenConfigEnabled(): void
    {
        $this->assertTrue($this->builder->isEnabled($this->store));
    }
}
