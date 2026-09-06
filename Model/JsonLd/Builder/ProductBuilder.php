<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\IdFactory;
use Angeo\RichData\Model\Product\PriceResolver;
use Angeo\RichData\Model\Product\StockResolver;
use Angeo\RichData\Model\Product\VariantResolver;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds the Product (or ProductGroup) node.
 *
 * Context keys:
 *   'product'   => ProductInterface  - required
 *   'page_url'  => string            - canonical url of the page being rendered
 */
class ProductBuilder extends AbstractBuilder
{
    private const DEFAULT_MAX_VARIANTS = 20;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly StockResolver $stockResolver,
        private readonly PriceResolver $priceResolver,
        private readonly VariantResolver $variantResolver,
        private readonly ImageHelper $imageHelper,
        private readonly ReviewFactory $reviewFactory,
        private readonly IdFactory $idFactory,
    ) {
        parent::__construct($scopeConfig);
    }

    public function getType(): string
    {
        return 'product';
    }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/product/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        $product = $context['product'] ?? null;
        if (!($product instanceof ProductInterface)) {
            return null;
        }

        $storeId  = (int) $store->getId();
        $pageUrl  = (string) ($context['page_url'] ?? $this->productUrl($product));
        $currency = (string) $store->getCurrentCurrencyCode();

        $useVariants = $this->isConfigEnabled('angeo_rich_data/product/include_variants', $store)
            && $this->variantResolver->isConfigurable($product);

        $variants = $useVariants ? $this->collectVariants($product, $store, $currency, $storeId, $pageUrl) : [];
        $isGroup  = $variants !== [];

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => $isGroup ? 'ProductGroup' : 'Product',
            '@id'      => $this->idFactory->forPage(
                $pageUrl,
                $isGroup ? IdFactory::FRAGMENT_PRODUCT_GRP : IdFactory::FRAGMENT_PRODUCT
            ),
            'name'     => (string) $product->getName(),
            'url'      => $pageUrl,
        ];

        $schema['mainEntityOfPage'] = $pageUrl;

        $description = $this->resolveDescription($product);
        if ($description !== '') {
            $schema['description'] = $description;
        }

        $image = $this->resolveImage($product);
        if ($image !== null) {
            $schema['image'] = $image;
        }

        if ($this->isConfigEnabled('angeo_rich_data/product/include_sku', $store)) {
            $sku = trim((string) $product->getSku());
            if ($sku !== '') {
                $schema['sku'] = $sku;
            }
        }

        if ($this->isConfigEnabled('angeo_rich_data/product/include_identifiers', $store)) {
            $this->addIdentifiers($schema, $product, $store);
        }

        $brand = $this->resolveBrand($product, $store);
        if ($brand !== null) {
            $schema['brand'] = $brand;
        }

        if ($isGroup) {
            $schema['productGroupID'] = (string) $product->getSku();

            $variesBy = $this->variantResolver->getVariesBy($product);
            if ($variesBy !== []) {
                $schema['variesBy'] = $variesBy;
            }

            $schema['hasVariant'] = $variants;
        } else {
            $schema['offers'] = $this->buildOffers($product, $store, $currency, $storeId, $pageUrl);
        }

        if ($this->isConfigEnabled('angeo_rich_data/product/include_aggregate_rating', $store)) {
            $rating = $this->buildAggregateRating($product, $storeId);
            if ($rating !== null) {
                $schema['aggregateRating'] = $rating;
            }
        }

        return $schema;
    }

    /**
     * Build one Product node per configurable child.
     *
     * @return array<int, array>
     */
    private function collectVariants(
        ProductInterface $product,
        StoreInterface $store,
        string $currency,
        int $storeId,
        string $parentUrl
    ): array {
        $max = (int) $this->getConfig('angeo_rich_data/product/max_variants', $store);
        if ($max <= 0) {
            $max = self::DEFAULT_MAX_VARIANTS;
        }
        $max = min($max, VariantResolver::MAX_VARIANTS);

        $children = $this->variantResolver->getVariants($product);
        if ($children === []) {
            return [];
        }

        $parentGroupId = (string) $product->getSku();
        $variesBy      = $this->variantResolver->getVariesBy($product);
        $variants      = [];

        foreach (array_slice($children, 0, $max) as $child) {
            $childSku = trim((string) $child->getSku());
            if ($childSku === '') {
                continue;
            }

            $node = [
                '@type'                 => 'Product',
                '@id'                   => $parentUrl . '#variant-' . rawurlencode($childSku),
                'sku'                   => $childSku,
                'name'                  => (string) ($child->getName() ?: $product->getName()),
                'inProductGroupWithID'  => $parentGroupId,
                'offers'                => $this->buildOffers($child, $store, $currency, $storeId, $parentUrl),
            ];

            foreach ($variesBy as $property) {
                $value = $this->attributeValue($child, $property);
                if ($value !== '') {
                    $node[$property] = $value;
                }
            }

            $childImage = $this->resolveImage($child);
            if ($childImage !== null) {
                $node['image'] = $childImage;
            }

            $variants[] = $node;
        }

        return $variants;
    }

    /**
     * A single Offer, or an AggregateOffer when the product has a price range.
     */
    private function buildOffers(
        ProductInterface $product,
        StoreInterface $store,
        string $currency,
        int $storeId,
        string $pageUrl
    ): array {
        $price       = $this->priceResolver->resolve($product);
        $inStock     = $this->stockResolver->isInStock($product, $storeId);
        $availability = $inStock
            ? ($this->getConfig('angeo_rich_data/product/availability_in_stock', $store) ?: 'https://schema.org/InStock')
            : ($this->getConfig('angeo_rich_data/product/availability_out_of_stock', $store) ?: 'https://schema.org/OutOfStock');

        $url = $this->productUrl($product) ?: $pageUrl;

        $seller = [
            '@type' => 'Organization',
            '@id'   => $this->idFactory->organization($store),
            'name'  => (string) $store->getName(),
        ];

        if ($price['is_range']) {
            $offers = [
                '@type'         => 'AggregateOffer',
                'priceCurrency' => $currency,
                'lowPrice'      => $this->priceResolver->format($price['min']),
                'highPrice'     => $this->priceResolver->format($price['max']),
                'availability'  => $availability,
                'url'           => $url,
                'seller'        => $seller,
            ];
        } else {
            $offers = [
                '@type'         => 'Offer',
                'price'         => $this->priceResolver->format($price['min']),
                'priceCurrency' => $currency,
                'availability'  => $availability,
                'url'           => $url,
                'seller'        => $seller,
            ];

            $validUntil = $this->resolvePriceValidUntil($store);
            if ($validUntil !== null) {
                $offers['priceValidUntil'] = $validUntil;
            }
        }

        $condition = $this->getConfig('angeo_rich_data/product/condition', $store);
        if ($condition !== '') {
            $offers['itemCondition'] = 'https://schema.org/' . $condition;
        }

        $returnPolicy = $this->buildReturnPolicy($store);
        if ($returnPolicy !== null) {
            $offers['hasMerchantReturnPolicy'] = $returnPolicy;
        }

        $shipping = $this->buildShippingDetails($store, $currency);
        if ($shipping !== null) {
            $offers['shippingDetails'] = $shipping;
        }

        return $offers;
    }

    /**
     * 1.x hard-coded 31 December of next year through a date() format string
     * whose literal digits only worked by accident. The window is now a plain
     * number of days, and can be switched off.
     */
    private function resolvePriceValidUntil(StoreInterface $store): ?string
    {
        $days = (int) $this->getConfig('angeo_rich_data/product/price_valid_days', $store);
        if ($days <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . time()))
            ->modify('+' . $days . ' days')
            ->format('Y-m-d');
    }

    /**
     * ProductInterface does not declare getProductUrl(); the storefront always
     * passes the Product model, but a custom caller may not.
     */
    private function productUrl(ProductInterface $product): string
    {
        return method_exists($product, 'getProductUrl') ? (string) $product->getProductUrl() : '';
    }

    private function attributeValue(ProductInterface $product, string $attributeCode): string
    {
        $value = null;

        if (method_exists($product, 'getAttributeText')) {
            $value = $product->getAttributeText($attributeCode);
        }
        if (($value === null || $value === false || $value === '') && method_exists($product, 'getData')) {
            $value = $product->getData($attributeCode);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function textValue(ProductInterface $product, string $getter): string
    {
        return method_exists($product, $getter) ? (string) $product->{$getter}() : '';
    }

    private function addIdentifiers(array &$schema, ProductInterface $product, StoreInterface $store): void
    {
        $gtinAttr = $this->getConfig('angeo_rich_data/product/gtin_attribute', $store);
        if ($gtinAttr !== '') {
            $gtin = $this->attributeValue($product, $gtinAttr);
            if ($gtin !== '') {
                $schema['gtin'] = $gtin;
            }
        }

        $mpnAttr = $this->getConfig('angeo_rich_data/product/mpn_attribute', $store);
        if ($mpnAttr !== '') {
            $mpn = $this->attributeValue($product, $mpnAttr);
            if ($mpn !== '') {
                $schema['mpn'] = $mpn;
            }
        }
    }

    private function resolveBrand(ProductInterface $product, StoreInterface $store): ?array
    {
        if (!$this->isConfigEnabled('angeo_rich_data/product/include_brand', $store)) {
            return null;
        }

        $attribute = $this->getConfig('angeo_rich_data/product/brand_attribute', $store) ?: 'manufacturer';
        $value     = $this->attributeValue($product, $attribute);

        return $value !== '' ? ['@type' => 'Brand', 'name' => $value] : null;
    }

    private function resolveDescription(ProductInterface $product): string
    {
        $raw = $this->textValue($product, 'getShortDescription') ?: $this->textValue($product, 'getDescription');
        $raw = strip_tags($raw);
        $raw = (string) preg_replace('/\s+/u', ' ', $raw);

        return mb_substr(trim($raw), 0, 5000);
    }

    private function resolveImage(ProductInterface $product): ?string
    {
        try {
            $url = $this->imageHelper
                ->init($product, 'product_page_image_large')
                ->setImageFile($this->textValue($product, 'getImage'))
                ->getUrl();

            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildReturnPolicy(StoreInterface $store): ?array
    {
        if (!$this->isConfigEnabled('angeo_rich_data/merchant_policies/return_enabled', $store)) {
            return null;
        }

        $days      = (int) $this->getConfig('angeo_rich_data/merchant_policies/return_days', $store);
        $countries = $this->toList($this->getConfig('angeo_rich_data/merchant_policies/return_country', $store));

        $policy = [
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => count($countries) > 1 ? $countries : ($countries[0] ?? 'US'),
            'returnPolicyCategory' => $days > 0
                ? 'https://schema.org/MerchantReturnFiniteReturnWindow'
                : 'https://schema.org/MerchantReturnNotPermitted',
        ];

        if ($days > 0) {
            $policy['merchantReturnDays'] = $days;
            $policy['returnMethod']       = 'https://schema.org/ReturnByMail';

            $feeType = $this->getConfig('angeo_rich_data/merchant_policies/return_fee', $store) ?: 'FreeReturn';
            $policy['returnFees'] = 'https://schema.org/' . $feeType;
        }

        return $policy;
    }

    private function buildShippingDetails(StoreInterface $store, string $currency): ?array
    {
        if (!$this->isConfigEnabled('angeo_rich_data/merchant_policies/shipping_enabled', $store)) {
            return null;
        }

        $rateRaw = $this->getConfig('angeo_rich_data/merchant_policies/shipping_rate', $store);
        $rate    = $rateRaw === '' ? '0.00' : number_format((float) $rateRaw, 2, '.', '');

        $countries = $this->toList($this->getConfig('angeo_rich_data/merchant_policies/shipping_country', $store));

        $details = [
            '@type'        => 'OfferShippingDetails',
            'shippingRate' => [
                '@type'    => 'MonetaryAmount',
                'value'    => $rate,
                'currency' => $currency,
            ],
            'shippingDestination' => [
                '@type'          => 'DefinedRegion',
                'addressCountry' => count($countries) > 1 ? $countries : ($countries[0] ?? 'US'),
            ],
        ];

        $handlingMin = $this->getConfig('angeo_rich_data/merchant_policies/handling_days_min', $store);
        $handlingMax = $this->getConfig('angeo_rich_data/merchant_policies/handling_days_max', $store);
        $transitMin  = $this->getConfig('angeo_rich_data/merchant_policies/transit_days_min', $store);
        $transitMax  = $this->getConfig('angeo_rich_data/merchant_policies/transit_days_max', $store);

        if ($handlingMin !== '' || $handlingMax !== '' || $transitMin !== '' || $transitMax !== '') {
            $details['deliveryTime'] = [
                '@type'        => 'ShippingDeliveryTime',
                'handlingTime' => [
                    '@type'    => 'QuantitativeValue',
                    'minValue' => (int) ($handlingMin !== '' ? $handlingMin : 0),
                    'maxValue' => (int) ($handlingMax !== '' ? $handlingMax : 1),
                    'unitCode' => 'DAY',
                ],
                'transitTime' => [
                    '@type'    => 'QuantitativeValue',
                    'minValue' => (int) ($transitMin !== '' ? $transitMin : 1),
                    'maxValue' => (int) ($transitMax !== '' ? $transitMax : 5),
                    'unitCode' => 'DAY',
                ],
            ];
        }

        return $details;
    }

    private function buildAggregateRating(ProductInterface $product, int $storeId): ?array
    {
        try {
            $review = $this->reviewFactory->create();
            $review->getEntitySummary($product, $storeId);

            $summary = method_exists($product, 'getRatingSummary') ? $product->getRatingSummary() : null;
            if ($summary === null) {
                return null;
            }

            $reviewCount = (int) ($summary->getReviewsCount() ?? 0);
            if ($reviewCount === 0) {
                return null;
            }

            return [
                '@type'       => 'AggregateRating',
                'ratingValue' => round((float) $summary->getRatingSummary() / 20, 1),
                'reviewCount' => $reviewCount,
                'bestRating'  => 5,
                'worstRating' => 1,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
