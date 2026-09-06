<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Page;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Collects the products that back the ItemList on a category page.
 *
 * Two fixes over 1.x:
 *
 * 1. The collection used to select nothing but the name and then call
 *    getProductUrl() on each of fifty products, which is fifty extra url_rewrite
 *    queries per page view. addUrlRewrite() resolves them in the same query.
 *
 * 2. The list always started at product one regardless of which page the visitor
 *    was on, so page 3 of a category published the items from page 1. The
 *    request's paging and sort parameters are now applied.
 *
 * Known limit: layered-navigation filters are not applied, because the layer has
 * not been resolved yet at the point the head is rendered. On a filtered listing
 * the ItemList therefore describes the unfiltered category.
 */
class CategoryProductsProvider
{
    public const MAX_ITEMS = 50;

    private const ALLOWED_SORT = ['position', 'name', 'price', 'created_at'];

    public function __construct(
        private readonly HttpRequest $request,
        private readonly ImageHelper $imageHelper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{name: string, url: string, image?: string, sku?: string}>
     */
    public function getProducts(CategoryInterface $category, StoreInterface $store): array
    {
        $products = [];

        try {
            if (!method_exists($category, 'getProductCollection')) {
                return [];
            }

            $collection = $category->getProductCollection();
            $collection->addAttributeToSelect(['name', 'small_image', 'image'])
                ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                ->setVisibility([Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH])
                ->addUrlRewrite($category->getId());

            $this->applySortOrder($collection);

            $collection->setPageSize($this->resolvePageSize())
                ->setCurPage($this->resolveCurrentPage());

            foreach ($collection as $product) {
                $name = trim((string) $product->getName());
                $url  = (string) $product->getProductUrl();
                if ($name === '' || $url === '') {
                    continue;
                }

                $item = ['name' => $name, 'url' => $url];

                $sku = trim((string) $product->getSku());
                if ($sku !== '') {
                    $item['sku'] = $sku;
                }

                $image = $this->resolveImage($product);
                if ($image !== null) {
                    $item['image'] = $image;
                }

                $products[] = $item;
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Category product list failed: ' . $e->getMessage());
        }

        return $products;
    }

    private function resolvePageSize(): int
    {
        $limit = (int) $this->request->getParam('product_list_limit', 0);
        if ($limit <= 0) {
            return self::MAX_ITEMS;
        }

        return min($limit, self::MAX_ITEMS);
    }

    private function resolveCurrentPage(): int
    {
        $page = (int) $this->request->getParam('p', 1);

        return $page > 0 ? $page : 1;
    }

    private function applySortOrder($collection): void
    {
        $order = (string) $this->request->getParam('product_list_order', '');
        if (!in_array($order, self::ALLOWED_SORT, true)) {
            return;
        }

        $direction = strtolower((string) $this->request->getParam('product_list_dir', 'asc'));
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $collection->setOrder($order, $direction);
    }

    private function resolveImage($product): ?string
    {
        try {
            $url = $this->imageHelper
                ->init($product, 'category_page_grid')
                ->getUrl();

            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
