<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Page;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Single place that answers "what is this page about".
 *
 * In 1.x the deprecated Magento\Framework\Registry was called straight from the
 * ViewModel. Magento still offers no non-deprecated way to reach the already
 * loaded storefront product, and re-loading it from the repository on every page
 * view would be a needless query, so the registry lookup stays — but it is now
 * confined to this class, with a repository fallback when the registry is empty.
 * When Magento ships a replacement, one file changes instead of the ViewModel.
 */
class CurrentEntity
{
    public const PAGE_PRODUCT  = 'catalog_product_view';
    public const PAGE_CATEGORY = 'catalog_category_view';
    public const PAGE_CMS      = 'cms_page_view';
    public const PAGE_HOME     = 'cms_index_index';

    public function __construct(
        private readonly Registry $registry,
        private readonly HttpRequest $request,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getPageType(): string
    {
        return (string) $this->request->getFullActionName();
    }

    public function getProduct(StoreInterface $store): ?ProductInterface
    {
        $product = $this->registry->registry('current_product');
        if ($product instanceof ProductInterface && $product->getId()) {
            return $product;
        }

        $productId = (int) $this->request->getParam('id');
        if ($productId <= 0) {
            return null;
        }

        try {
            return $this->productRepository->getById($productId, false, (int) $store->getId());
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Product lookup failed: ' . $e->getMessage());

            return null;
        }
    }

    public function getCategory(StoreInterface $store): ?CategoryInterface
    {
        $category = $this->registry->registry('current_category');
        if ($category instanceof CategoryInterface && $category->getId()) {
            return $category;
        }

        $categoryId = (int) $this->request->getParam('id');
        if ($categoryId <= 0) {
            return null;
        }

        try {
            return $this->categoryRepository->get($categoryId, (int) $store->getId());
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Category lookup failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Page number of a paginated listing. Used so page 2 of a category does not
     * publish the same @id as page 1 while listing different products.
     */
    public function getListingPage(): int
    {
        $page = (int) $this->request->getParam('p', 1);

        return $page > 1 ? $page : 1;
    }

    public function getCmsPage(): ?PageInterface
    {
        $page = $this->registry->registry('cms_page');

        return $page instanceof PageInterface ? $page : null;
    }
}
