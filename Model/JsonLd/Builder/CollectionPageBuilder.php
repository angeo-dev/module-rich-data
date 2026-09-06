<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\IdFactory;
use Angeo\RichData\Model\Page\CategoryProductsProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds CollectionPage + ItemList for category pages.
 *
 * Context keys:
 *   'category'          => CategoryInterface
 *   'category_products' => array<int, array{name, url, image?, sku?}>
 *   'page_url'          => string
 */
class CollectionPageBuilder extends AbstractBuilder
{
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly IdFactory $idFactory,
    ) {
        parent::__construct($scopeConfig);
    }

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
            return null;
        }

        $items    = [];
        $position = 1;

        foreach ($products as $product) {
            $name = trim((string) ($product['name'] ?? ''));
            $url  = trim((string) ($product['url'] ?? ''));
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

            if (count($items) >= CategoryProductsProvider::MAX_ITEMS) {
                break;
            }
        }

        if ($items === []) {
            return null;
        }

        $pageUrl = (string) ($context['page_url'] ?? ($category->getUrl() ?: $this->idFactory->base($store)));

        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'CollectionPage',
            '@id'        => $this->idFactory->forPage($pageUrl, IdFactory::FRAGMENT_COLLECTION),
            'name'       => (string) $category->getName(),
            'url'        => $pageUrl,
            'isPartOf'   => $this->idFactory->ref($this->idFactory->website($store)),
            'mainEntity' => [
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
