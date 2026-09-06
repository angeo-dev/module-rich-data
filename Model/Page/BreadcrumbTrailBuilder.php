<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Page;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the Home -> categories -> current page trail.
 *
 * 1.x called CategoryRepository::get() once per category the product belonged
 * to, and then once more for every step of the winning path — a product in eight
 * categories cost a dozen loads per page view. Every category needed here is now
 * fetched in a single collection query.
 *
 * @phpcs:disable Magento2.Functions.DiscouragedFunction
 */
class BreadcrumbTrailBuilder
{
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    public function forProduct(ProductInterface $product, StoreInterface $store): array
    {
        $crumbs = [$this->homeCrumb($store)];

        try {
            $categoryIds = method_exists($product, 'getCategoryIds')
                ? array_map('intval', (array) $product->getCategoryIds())
                : [];

            if ($categoryIds !== []) {
                $categories = $this->loadCategories($categoryIds, $store);
                $deepest    = $this->pickDeepest($categories);

                if ($deepest !== null) {
                    foreach ($this->trailFor($deepest, $store) as $crumb) {
                        $crumbs[] = $crumb;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Product breadcrumb failed: ' . $e->getMessage());
        }

        // The current page carries no url of its own.
        $crumbs[] = ['name' => (string) $product->getName(), 'url' => ''];

        return $crumbs;
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    public function forCategory(CategoryInterface $category, StoreInterface $store): array
    {
        $crumbs = [$this->homeCrumb($store)];

        try {
            $trail = $this->trailFor($category, $store);
            // Drop the url of the last step: it is the page being viewed.
            $last = array_key_last($trail);
            if ($last !== null) {
                $trail[$last]['url'] = '';
            }
            foreach ($trail as $crumb) {
                $crumbs[] = $crumb;
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Category breadcrumb failed: ' . $e->getMessage());
        }

        return count($crumbs) > 1 ? $crumbs : [];
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    public function forCmsPage(string $title, StoreInterface $store): array
    {
        $title = trim($title);
        if ($title === '') {
            return [];
        }

        return [
            $this->homeCrumb($store),
            ['name' => $title, 'url' => ''],
        ];
    }

    /**
     * @return array{name: string, url: string}
     */
    private function homeCrumb(StoreInterface $store): array
    {
        return ['name' => 'Home', 'url' => rtrim((string) $store->getBaseUrl(), '/')];
    }

    /**
     * Walk the category path, skipping the catalog root and the store root.
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function trailFor(CategoryInterface $category, StoreInterface $store): array
    {
        $pathIds = array_map('intval', array_filter(explode('/', (string) $category->getPath())));
        $pathIds = array_slice($pathIds, 2);

        if ($pathIds === []) {
            return [];
        }

        $categories = $this->loadCategories($pathIds, $store);

        $trail = [];
        foreach ($pathIds as $pathId) {
            $node = $categories[$pathId] ?? null;
            if ($node === null || !$node->getIsActive()) {
                continue;
            }
            $trail[] = [
                'name' => (string) $node->getName(),
                'url'  => (string) $node->getUrl(),
            ];
        }

        return $trail;
    }

    /**
     * One query for every category id we need.
     *
     * @param int[] $ids
     * @return array<int, CategoryInterface>
     */
    private function loadCategories(array $ids, StoreInterface $store): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStore($store)
            ->addAttributeToSelect(['name', 'is_active', 'url_key', 'url_path'])
            ->addFieldToFilter('entity_id', ['in' => $ids]);

        $result = [];
        foreach ($collection as $category) {
            $result[(int) $category->getId()] = $category;
        }

        return $result;
    }

    /**
     * @param array<int, CategoryInterface> $categories
     */
    private function pickDeepest(array $categories): ?CategoryInterface
    {
        $deepest = null;
        $depth   = -1;

        foreach ($categories as $category) {
            if (!$category->getIsActive()) {
                continue;
            }
            $current = substr_count((string) $category->getPath(), '/');
            if ($current > $depth) {
                $depth   = $current;
                $deepest = $category;
            }
        }

        return $deepest;
    }
}
