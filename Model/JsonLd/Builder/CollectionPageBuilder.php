<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds CollectionPage + ItemList JSON-LD for category pages.
 *
 * AI shopping engines (notably the Gemini Shopping Graph) read an ItemList on
 * the category page to understand which products belong to a collection. Stock
 * Magento emits no such schema, so without this builder a category page has no
 * machine-readable product listing.
 *
 * Context keys (set by the ViewModel for catalog_category_view):
 *   'category'        => CategoryInterface  — the current category
 *   'category_products' => array<int, array{name,url,image?,sku?}>  — listed products
 */
class CollectionPageBuilder extends AbstractBuilder
{
    /** Hard cap so the ItemList never bloats the page. */
    private const MAX_ITEMS = 50;

    public function getType(): string
    {
        return 'collection_page';
    }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/collection_page/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        $category = $context['category'] ?? null;
        if (!$category || !method_exists($category, 'getId') || !$category->getId()) {
            return null;
        }

        $products = $context['category_products'] ?? [];
        if (!is_array($products) || $products === []) {
            // No products to list — emit CollectionPage without an empty ItemList
            // only if we at least have a name; otherwise skip.
            return null;
        }

        $items = [];
        $position = 1;
        foreach ($products as $product) {
            $name = isset($product['name']) ? trim((string) $product['name']) : '';
            $url  = isset($product['url']) ? trim((string) $product['url']) : '';
            if ($name === '' || $url === '') {
                continue;
            }

            $listItem = [
                '@type'    => 'ListItem',
                'position' => $position,
                'url'      => $url,
                'name'     => $name,
            ];

            if (!empty($product['image'])) {
                $listItem['image'] = (string) $product['image'];
            }

            $items[] = $listItem;
            $position++;

            if ($position > self::MAX_ITEMS) {
                break;
            }
        }

        if ($items === []) {
            return null;
        }

        $categoryName = (string) $category->getName();
        $baseUrl      = rtrim($store->getBaseUrl(), '/');
        $categoryUrl  = method_exists($category, 'getUrl') ? (string) $category->getUrl() : $baseUrl;

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => $categoryName,
            'url'         => $categoryUrl,
            'mainEntity'  => [
                '@type'           => 'ItemList',
                'numberOfItems'   => count($items),
                'itemListElement' => $items,
            ],
        ];

        $description = method_exists($category, 'getMetaDescription')
            ? trim((string) $category->getMetaDescription())
            : '';
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }
}
