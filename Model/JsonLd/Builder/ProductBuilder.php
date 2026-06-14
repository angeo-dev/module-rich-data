<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds Product JSON-LD schema.
 *
 * Fixes the ProductSchemaChecker and "Merchant policies" signals in
 * angeo/module-aeo-audit:
 *   - @type: Product
 *   - name, description, image, url, sku
 *   - offers.price, offers.priceCurrency, offers.availability  (critical for ChatGPT Shopping)
 *   - offers.url (seller page)
 *   - offers.hasMerchantReturnPolicy  (required by Google & ChatGPT Shopping since Jan 2026)
 *   - offers.shippingDetails          (required for full structured-data eligibility)
 *   - aggregateRating (when reviews exist)
 *   - gtin / mpn (optional, improves product matching)
 *   - brand (configurable, off by default)
 *
 * Context keys:
 *   'product' => ProductInterface   - required
 */
class ProductBuilder extends AbstractBuilder
{
    public function __construct(
        ScopeConfigInterface                         $scopeConfig,
        private readonly StockRegistryInterface      $stockRegistry,
        private readonly ImageHelper                 $imageHelper,
        private readonly PriceCurrencyInterface      $priceCurrency,
        private readonly ReviewFactory               $reviewFactory,
    ) {
        parent::__construct($scopeConfig);
    }

    public function getType(): string { return 'product'; }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/product/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        /** @var Product|null $product */
        $product = $context['product'] ?? null;
        if (!($product instanceof ProductInterface)) {
            return null;
        }

        $storeId       = (int) $store->getId();
        $currencyCode  = $store->getCurrentCurrencyCode();
        $price         = (float) $product->getFinalPrice();
        $isInStock     = $this->getStockStatus($product, $storeId);

        $availability = $isInStock
            ? ($this->getConfig('angeo_rich_data/product/availability_in_stock', $store) ?: 'https://schema.org/InStock')
            : ($this->getConfig('angeo_rich_data/product/availability_out_of_stock', $store) ?: 'https://schema.org/OutOfStock');

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $product->getName(),
            'url'      => $product->getProductUrl(),
        ];

        // Description - use short_description first, fall back to description
        $desc = strip_tags((string) ($product->getShortDescription() ?: $product->getDescription()));
        $desc = trim(preg_replace('/\s+/', ' ', $desc));
        if ($desc) {
            $schema['description'] = mb_substr($desc, 0, 5000);
        }

        // Image
        $imageUrl = $this->getProductImageUrl($product, $store);
        if ($imageUrl) {
            $schema['image'] = $imageUrl;
        }

        // SKU
        if ($this->isConfigEnabled('angeo_rich_data/product/include_sku', $store)) {
            $schema['sku'] = $product->getSku();
        }

        // GTIN / MPN - optional identifiers that improve AI/Google matching
        if ($this->isConfigEnabled('angeo_rich_data/product/include_identifiers', $store)) {
            $this->addProductIdentifiers($schema, $product, $store);
        }

        // Brand
        if ($this->isConfigEnabled('angeo_rich_data/product/include_brand', $store)) {
            $brandAttr = $this->getConfig('angeo_rich_data/product/brand_attribute', $store) ?: 'manufacturer';
            $brandValue = $product->getAttributeText($brandAttr) ?: $product->getData($brandAttr);
            if ($brandValue) {
                $schema['brand'] = ['@type' => 'Brand', 'name' => (string) $brandValue];
            }
        }

        // Offers - the most critical part for ChatGPT Shopping
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => number_format($price, 2, '.', ''),
            'priceCurrency' => $currencyCode,
            'availability'  => $availability,
            'url'           => $product->getProductUrl(),
            'priceValidUntil' => date('Y-12-31', strtotime('+1 year')),
            'seller'        => [
                '@type' => 'Organization',
                'name'  => $store->getName(),
            ],
        ];

        // Condition
        $condition = $this->getConfig('angeo_rich_data/product/condition', $store);
        if ($condition) {
            $schema['offers']['itemCondition'] = 'https://schema.org/' . $condition;
        }

        // Merchant return policy - required by Google & ChatGPT Shopping since Jan 2026
        $returnPolicy = $this->buildReturnPolicy($store);
        if ($returnPolicy !== null) {
            $schema['offers']['hasMerchantReturnPolicy'] = $returnPolicy;
        }

        // Shipping details - required for full structured-data eligibility
        $shippingDetails = $this->buildShippingDetails($store, $currencyCode);
        if ($shippingDetails !== null) {
            $schema['offers']['shippingDetails'] = $shippingDetails;
        }

        // AggregateRating - requires at least one approved review
        if ($this->isConfigEnabled('angeo_rich_data/product/include_aggregate_rating', $store)) {
            $rating = $this->buildAggregateRating($product, $storeId);
            if ($rating !== null) {
                $schema['aggregateRating'] = $rating;
            }
        }

        return $schema;
    }

    /**
     * Add GTIN / MPN identifiers from configured product attributes.
     */
    private function addProductIdentifiers(array &$schema, Product $product, StoreInterface $store): void
    {
        $gtinAttr = $this->getConfig('angeo_rich_data/product/gtin_attribute', $store);
        if ($gtinAttr) {
            $gtin = trim((string) $product->getData($gtinAttr));
            if ($gtin !== '') {
                $schema['gtin'] = $gtin;
            }
        }

        $mpnAttr = $this->getConfig('angeo_rich_data/product/mpn_attribute', $store);
        if ($mpnAttr) {
            $mpn = trim((string) $product->getData($mpnAttr));
            if ($mpn !== '') {
                $schema['mpn'] = $mpn;
            }
        }
    }

    /**
     * Build MerchantReturnPolicy from store config.
     * Returns null when the feature is disabled.
     */
    private function buildReturnPolicy(StoreInterface $store): ?array
    {
        if (!$this->isConfigEnabled('angeo_rich_data/merchant_policies/return_enabled', $store)) {
            return null;
        }

        $days = (int) $this->getConfig('angeo_rich_data/merchant_policies/return_days', $store);
        $countryRaw = $this->getConfig('angeo_rich_data/merchant_policies/return_country', $store);
        $countries = array_values(array_filter(array_map('trim', explode(',', $countryRaw))));

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

    /**
     * Build OfferShippingDetails from store config.
     * Returns null when the feature is disabled.
     */
    private function buildShippingDetails(StoreInterface $store, string $currencyCode): ?array
    {
        if (!$this->isConfigEnabled('angeo_rich_data/merchant_policies/shipping_enabled', $store)) {
            return null;
        }

        $rate = $this->getConfig('angeo_rich_data/merchant_policies/shipping_rate', $store);
        $rate = $rate === '' ? '0.00' : number_format((float) $rate, 2, '.', '');

        $countryRaw = $this->getConfig('angeo_rich_data/merchant_policies/shipping_country', $store);
        $countries  = array_values(array_filter(array_map('trim', explode(',', $countryRaw))));
        $shipCountry = count($countries) > 1 ? $countries : ($countries[0] ?? 'US');

        $details = [
            '@type'            => 'OfferShippingDetails',
            'shippingRate'     => [
                '@type'         => 'MonetaryAmount',
                'value'         => $rate,
                'currency'      => $currencyCode,
            ],
            'shippingDestination' => [
                '@type'          => 'DefinedRegion',
                'addressCountry' => $shipCountry,
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
                'transitTime'  => [
                    '@type'    => 'QuantitativeValue',
                    'minValue' => (int) ($transitMin !== '' ? $transitMin : 1),
                    'maxValue' => (int) ($transitMax !== '' ? $transitMax : 5),
                    'unitCode' => 'DAY',
                ],
            ];
        }

        return $details;
    }

    private function getStockStatus(Product $product, int $storeId): bool
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem($product->getId(), $storeId);
            return $stockItem->getIsInStock();
        } catch (\Exception) {
            return true; // optimistic default
        }
    }

    private function getProductImageUrl(Product $product, StoreInterface $store): ?string
    {
        try {
            $url = $this->imageHelper
                ->init($product, 'product_page_image_large')
                ->setImageFile($product->getImage())
                ->getUrl();
            return $url ?: null;
        } catch (\Exception) {
            return null;
        }
    }

    private function buildAggregateRating(Product $product, int $storeId): ?array
    {
        try {
            /** @var \Magento\Review\Model\Review $review */
            $review = $this->reviewFactory->create();
            $review->getEntitySummary($product, $storeId);

            $ratingSummary = $product->getRatingSummary();
            $reviewCount   = (int) ($ratingSummary?->getReviewsCount() ?? 0);

            if ($reviewCount === 0) {
                return null;
            }

            $ratingValue = round((float) $ratingSummary->getRatingSummary() / 20, 1); // 0-100 to 0-5

            return [
                '@type'       => 'AggregateRating',
                'ratingValue' => $ratingValue,
                'reviewCount' => $reviewCount,
                'bestRating'  => 5,
                'worstRating' => 1,
            ];
        } catch (\Exception) {
            return null;
        }
    }
}
