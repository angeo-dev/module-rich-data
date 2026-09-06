<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\Builder\ProductBuilder;
use Angeo\RichData\Model\JsonLd\IdFactory;
use Angeo\RichData\Model\Product\PriceResolver;
use Angeo\RichData\Model\Product\StockResolver;
use Angeo\RichData\Model\Product\VariantResolver;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductBuilderTest extends TestCase
{
    private const PAGE_URL = 'https://shop.test/jacket.html';

    private ScopeConfigInterface|MockObject $scopeConfig;
    private StockResolver|MockObject $stockResolver;
    private PriceResolver|MockObject $priceResolver;
    private VariantResolver|MockObject $variantResolver;
    private ImageHelper|MockObject $imageHelper;
    private ReviewFactory|MockObject $reviewFactory;
    private StoreInterface|MockObject $store;
    private Product|MockObject $product;
    private ProductBuilder $builder;

    /** @var array<string, string> */
    private array $config = [];

    /** @var array<string, bool> */
    private array $flags = [];

    protected function setUp(): void
    {
        $this->scopeConfig     = $this->createMock(ScopeConfigInterface::class);
        $this->stockResolver   = $this->createMock(StockResolver::class);
        $this->priceResolver   = $this->createMock(PriceResolver::class);
        $this->variantResolver = $this->createMock(VariantResolver::class);
        $this->imageHelper     = $this->createMock(ImageHelper::class);
        $this->reviewFactory   = $this->createMock(ReviewFactory::class);

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getName')->willReturn('Test Store');
        $this->store->method('getBaseUrl')->willReturn('https://shop.test/');
        $this->store->method('getCurrentCurrencyCode')->willReturn('EUR');

        $this->product = $this->createMock(Product::class);
        $this->product->method('getId')->willReturn(42);
        $this->product->method('getName')->willReturn('Alpine Jacket');
        $this->product->method('getSku')->willReturn('ALP-001');
        $this->product->method('getProductUrl')->willReturn(self::PAGE_URL);
        $this->product->method('getShortDescription')->willReturn('<p>Warm  jacket</p>');
        $this->product->method('getTypeId')->willReturn('simple');

        $this->imageHelper->method('init')->willReturnSelf();
        $this->imageHelper->method('setImageFile')->willReturnSelf();
        $this->imageHelper->method('getUrl')->willReturn('https://shop.test/media/jacket.jpg');

        $this->stockResolver->method('isInStock')->willReturn(true);
        $this->priceResolver->method('format')
            ->willReturnCallback(static fn (float $price) => number_format($price, 2, '.', ''));

        $this->flags = [
            'angeo_rich_data/general/enabled'                => true,
            'angeo_rich_data/product/enabled'                => true,
            'angeo_rich_data/product/include_sku'            => true,
            'angeo_rich_data/product/include_variants'       => false,
            'angeo_rich_data/product/include_brand'          => false,
            'angeo_rich_data/product/include_identifiers'    => false,
            'angeo_rich_data/product/include_aggregate_rating' => false,
            'angeo_rich_data/merchant_policies/return_enabled'   => false,
            'angeo_rich_data/merchant_policies/shipping_enabled' => false,
        ];
        $this->config = [
            'angeo_rich_data/product/condition'        => 'NewCondition',
            'angeo_rich_data/product/price_valid_days' => '365',
            'angeo_rich_data/product/max_variants'     => '20',
        ];

        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(fn (string $path) => $this->flags[$path] ?? false);
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(fn (string $path) => $this->config[$path] ?? '');

        $this->builder = new ProductBuilder(
            $this->scopeConfig,
            $this->stockResolver,
            $this->priceResolver,
            $this->variantResolver,
            $this->imageHelper,
            $this->reviewFactory,
            new IdFactory()
        );
    }

    public function testReturnsNullWithoutAProduct(): void
    {
        $this->assertNull($this->builder->build($this->store, []));
    }

    public function testSinglePriceProducesASingleOffer(): void
    {
        $this->givenPrice(99.0, 99.0, false);
        $this->variantResolver->method('isConfigurable')->willReturn(false);

        $schema = $this->build();

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('https://shop.test/jacket.html#product', $schema['@id']);
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertSame('99.00', $schema['offers']['price']);
        $this->assertSame('EUR', $schema['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        $this->assertSame('ALP-001', $schema['sku']);
        $this->assertSame('Warm jacket', $schema['description'], 'tags stripped, whitespace collapsed');
    }

    public function testSellerPointsAtTheSharedOrganizationNode(): void
    {
        $this->givenPrice(99.0, 99.0, false);
        $this->variantResolver->method('isConfigurable')->willReturn(false);

        $schema = $this->build();

        $this->assertSame('https://shop.test/#organization', $schema['offers']['seller']['@id']);
    }

    public function testPriceRangeProducesAnAggregateOffer(): void
    {
        $this->givenPrice(49.0, 89.0, true);
        $this->variantResolver->method('isConfigurable')->willReturn(false);

        $offers = $this->build()['offers'];

        $this->assertSame('AggregateOffer', $offers['@type']);
        $this->assertSame('49.00', $offers['lowPrice']);
        $this->assertSame('89.00', $offers['highPrice']);
        $this->assertArrayNotHasKey('price', $offers);
    }

    public function testOutOfStockUsesTheOutOfStockAvailability(): void
    {
        $this->givenPrice(10.0, 10.0, false);
        $this->variantResolver->method('isConfigurable')->willReturn(false);

        $stockResolver = $this->createMock(StockResolver::class);
        $stockResolver->method('isInStock')->willReturn(false);

        $builder = new ProductBuilder(
            $this->scopeConfig,
            $stockResolver,
            $this->priceResolver,
            $this->variantResolver,
            $this->imageHelper,
            $this->reviewFactory,
            new IdFactory()
        );

        $schema = $builder->build($this->store, ['product' => $this->product, 'page_url' => self::PAGE_URL]);

        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    public function testPriceValidUntilCanBeSwitchedOff(): void
    {
        $this->config['angeo_rich_data/product/price_valid_days'] = '0';
        $this->givenPrice(10.0, 10.0, false);
        $this->variantResolver->method('isConfigurable')->willReturn(false);

        $this->assertArrayNotHasKey('priceValidUntil', $this->build()['offers']);
    }

    public function testConfigurableProductBecomesAProductGroupWithVariants(): void
    {
        $this->flags['angeo_rich_data/product/include_variants'] = true;
        $this->givenPrice(59.0, 59.0, false);

        $this->variantResolver->method('isConfigurable')->willReturn(true);
        $this->variantResolver->method('getVariesBy')->willReturn(['color']);
        $this->variantResolver->method('getVariants')->willReturn([
            $this->childProduct('ALP-001-RED', 'Alpine Jacket Red', 'Red'),
            $this->childProduct('ALP-001-BLU', 'Alpine Jacket Blue', 'Blue'),
        ]);

        $schema = $this->build();

        $this->assertSame('ProductGroup', $schema['@type']);
        $this->assertSame('ALP-001', $schema['productGroupID']);
        $this->assertSame(['color'], $schema['variesBy']);
        $this->assertCount(2, $schema['hasVariant']);
        $this->assertArrayNotHasKey('offers', $schema, 'offers belong on the variants');

        $first = $schema['hasVariant'][0];
        $this->assertSame('ALP-001-RED', $first['sku']);
        $this->assertSame('ALP-001', $first['inProductGroupWithID']);
        $this->assertSame('Red', $first['color']);
        $this->assertNotEmpty($first['offers'], 'Google rejects a ProductGroup whose variants carry no offer');
    }

    public function testVariantCountIsCapped(): void
    {
        $this->flags['angeo_rich_data/product/include_variants'] = true;
        $this->config['angeo_rich_data/product/max_variants']    = '2';
        $this->givenPrice(59.0, 59.0, false);

        $children = [];
        for ($i = 1; $i <= 6; $i++) {
            $children[] = $this->childProduct('SKU-' . $i, 'Variant ' . $i, 'Colour ' . $i);
        }

        $this->variantResolver->method('isConfigurable')->willReturn(true);
        $this->variantResolver->method('getVariesBy')->willReturn(['color']);
        $this->variantResolver->method('getVariants')->willReturn($children);

        $this->assertCount(2, $this->build()['hasVariant']);
    }

    public function testMerchantPoliciesAreAddedWhenEnabled(): void
    {
        $this->flags['angeo_rich_data/merchant_policies/return_enabled']   = true;
        $this->flags['angeo_rich_data/merchant_policies/shipping_enabled'] = true;
        $this->config['angeo_rich_data/merchant_policies/return_days']     = '30';
        $this->config['angeo_rich_data/merchant_policies/return_country']  = 'NL,BE';
        $this->config['angeo_rich_data/merchant_policies/shipping_rate']   = '4.95';
        $this->config['angeo_rich_data/merchant_policies/shipping_country'] = 'NL';

        $this->givenPrice(10.0, 10.0, false);
        $this->variantResolver->method('isConfigurable')->willReturn(false);

        $offers = $this->build()['offers'];

        $this->assertSame(30, $offers['hasMerchantReturnPolicy']['merchantReturnDays']);
        $this->assertSame(['NL', 'BE'], $offers['hasMerchantReturnPolicy']['applicableCountry']);
        $this->assertSame('4.95', $offers['shippingDetails']['shippingRate']['value']);
        $this->assertSame('NL', $offers['shippingDetails']['shippingDestination']['addressCountry']);
    }

    private function build(): array
    {
        return $this->builder->build($this->store, [
            'product'  => $this->product,
            'page_url' => self::PAGE_URL,
        ]);
    }

    private function givenPrice(float $min, float $max, bool $isRange): void
    {
        $this->priceResolver->method('resolve')
            ->willReturn(['min' => $min, 'max' => $max, 'is_range' => $isRange]);
    }

    private function childProduct(string $sku, string $name, string $color): Product|MockObject
    {
        $child = $this->createMock(Product::class);
        $child->method('getSku')->willReturn($sku);
        $child->method('getName')->willReturn($name);
        $child->method('getId')->willReturn(crc32($sku));
        $child->method('getProductUrl')->willReturn(self::PAGE_URL);
        $child->method('getAttributeText')->willReturn($color);

        return $child;
    }
}
