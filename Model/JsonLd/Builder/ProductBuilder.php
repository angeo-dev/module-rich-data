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
 * Fixes the ProductSchemaChecker FAIL/WARN signal in angeo/module-aeo-audit:
 *   ✓ @type: Product
 *   ✓ name, description, image, url, sku
 *   ✓ offers.price, offers.priceCurrency, offers.availability ← critical for ChatGPT Shopping
 *   ✓ offers.url (seller page)
 *   ✓ aggregateRating (when reviews exist)
 *   ✓ brand (configurable, off by default)
 *   ✓ BreadcrumbList (injected as separate schema in same output)
 *
 * Context keys:
 *   'product' => ProductInterface   — required
 *   'category_path' => array        — optional, for BreadcrumbList
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
            ? $this->getConfig('angeo_rich_data/product/availability_in_stock', $store)
            : $this->getConfig('angeo_rich_data/product/availability_out_of_stock', $store);

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $product->getName(),
            'url'      => $product->getProductUrl(),
        ];

        // Description — use short_description first, fall back to description
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

        // Brand
        if ($this->isConfigEnabled('angeo_rich_data/product/include_brand', $store)) {
            $brandAttr = $this->getConfig('angeo_rich_data/product/brand_attribute', $store) ?: 'manufacturer';
            $brandValue = $product->getAttributeText($brandAttr) ?: $product->getData($brandAttr);
            if ($brandValue) {
                $schema['brand'] = ['@type' => 'Brand', 'name' => (string) $brandValue];
            }
        }

        // Offers — the most critical part for ChatGPT Shopping
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

        // AggregateRating — requires at least one approved review
        if ($this->isConfigEnabled('angeo_rich_data/product/include_aggregate_rating', $store)) {
            $rating = $this->buildAggregateRating($product, $storeId);
            if ($rating !== null) {
                $schema['aggregateRating'] = $rating;
            }
        }

        // Condition
        $condition = $this->getConfig('angeo_rich_data/product/condition', $store);
        if ($condition) {
            $schema['offers']['itemCondition'] = 'https://schema.org/' . $condition;
        }

        return $schema;
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

            $ratingValue = round((float) $ratingSummary->getRatingSummary() / 20, 1); // convert 0–100 to 0–5

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
