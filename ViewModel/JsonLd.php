<?php

declare(strict_types=1);

namespace Angeo\RichData\ViewModel;

use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\GetPageByIdentifier;
use Magento\Cms\Model\Page as CmsPage;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * ViewModel for JSON-LD schema injection.
 * Injected into head_scripts.phtml via layout XML argument.
 */
class JsonLd implements ArgumentInterface
{
    public function __construct(
        private readonly SchemaRenderer               $schemaRenderer,
        private readonly StoreManagerInterface        $storeManager,
        private readonly Registry                     $registry,
        private readonly HttpRequest                  $request,
        private readonly CategoryRepositoryInterface  $categoryRepository,
        private readonly LoggerInterface              $logger,
        private readonly GetPageByIdentifier          $getPageByIdentifier,
        private readonly ScopeConfigInterface         $scopeConfig,
    ) {}

    /**
     * Returns concatenated <script type="application/ld+json"> HTML
     * for the current page, or empty string if nothing to output.
     */
    public function getSchemaHtml(): string
    {
        try {
            $store    = $this->storeManager->getStore();
            $pageType = $this->request->getFullActionName();

            $context = ['page_type' => $pageType];

            if ($pageType === 'catalog_product_view') {
                /** @var Product|null $product */
                $product = $this->registry->registry('current_product');
                if (!$product?->getId()) {
                    return '';
                }
                $context['product'] = $product;
                // Build breadcrumb trail so BreadcrumbBuilder has data to render.
                $context['breadcrumbs'] = $this->buildBreadcrumbs($product, $store);
            }

            if (in_array($pageType, ['cms_index_index', 'cms_page_view'], true)) {
                $context['cms_page_content'] = $this->resolveCmsContent($store);
            }

            if ($pageType === 'catalog_category_view') {
                $category = $this->registry->registry('current_category');
                if (!$category?->getId()) {
                    return '';
                }
                $context['category'] = $category;
                $context['category_products'] = $this->buildCategoryProducts($category, $store);
            }

            return $this->schemaRenderer->render($store, $context);

        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] ViewModel failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Resolve the current CMS page content.
     *
     * On a CMS page view the page object is in the registry. On the homepage
     * (cms_index_index) the registry entry is frequently empty, so we fall back
     * to loading the configured home page (web/default/cms_home_page) via the
     * CMS PageRepository. This is why the FAQPage schema would not appear on the
     * homepage when relying on the registry alone.
     */
    private function resolveCmsContent($store): string
    {
        /** @var CmsPage|null $cmsPage */
        $cmsPage = $this->registry->registry('cms_page');
        if ($cmsPage && (string) $cmsPage->getContent() !== '') {
            return (string) $cmsPage->getContent();
        }

        // Fallback: load the configured home page.
        try {
            $homeIdentifier = (string) $this->scopeConfig->getValue(
                'web/default/cms_home_page',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            );
            if ($homeIdentifier === '') {
                return '';
            }

            // The config value may be "identifier" or "identifier|store_id".
            $identifier = explode('|', $homeIdentifier)[0];

            $page = $this->getPageByIdentifier->execute($identifier, (int) $store->getId());
            return (string) $page->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Home CMS content load failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Collect a lightweight product list for the current category to feed the
     * CollectionPage / ItemList schema. Capped to keep the payload small.
     *
     * @return array<int, array{name: string, url: string, image?: string}>
     */
    private function buildCategoryProducts($category, $store): array
    {
        $products = [];
        try {
            $collection = $category->getProductCollection()
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
                ->setVisibility([
                    \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG,
                    \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
                ]);
            $collection->setPageSize(50)->setCurPage(1);

            foreach ($collection as $product) {
                $name = trim((string) $product->getName());
                $url  = (string) $product->getProductUrl();
                if ($name === '' || $url === '') {
                    continue;
                }
                $products[] = ['name' => $name, 'url' => $url];
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Category product list failed: ' . $e->getMessage());
        }

        return $products;
    }

    /**
     * Build a breadcrumb trail (Home -> category path -> product) for the
     * current product. Uses the product's category collection; picks the
     * category with the deepest path so the trail is the most specific one.
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function buildBreadcrumbs(Product $product, $store): array
    {
        $crumbs = [];
        $baseUrl = rtrim($store->getBaseUrl(), '/');
        $crumbs[] = ['name' => 'Home', 'url' => $baseUrl];

        try {
            $categoryIds = $product->getCategoryIds();
            if (!empty($categoryIds)) {
                $deepest = null;
                $deepestDepth = -1;
                foreach ($categoryIds as $categoryId) {
                    try {
                        $category = $this->categoryRepository->get((int) $categoryId, (int) $store->getId());
                    } catch (\Throwable) {
                        continue;
                    }
                    if (!$category->getIsActive()) {
                        continue;
                    }
                    $depth = count(explode('/', (string) $category->getPath()));
                    if ($depth > $deepestDepth) {
                        $deepestDepth = $depth;
                        $deepest = $category;
                    }
                }

                if ($deepest !== null) {
                    // Walk the path from root category down to the deepest one.
                    $pathIds = explode('/', (string) $deepest->getPath());
                    // Skip the first two ids (root catalog + store root category).
                    $pathIds = array_slice($pathIds, 2);
                    foreach ($pathIds as $pathId) {
                        try {
                            $cat = $this->categoryRepository->get((int) $pathId, (int) $store->getId());
                        } catch (\Throwable) {
                            continue;
                        }
                        if (!$cat->getIsActive()) {
                            continue;
                        }
                        $crumbs[] = [
                            'name' => (string) $cat->getName(),
                            'url'  => (string) $cat->getUrl(),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Breadcrumb build failed: ' . $e->getMessage());
        }

        // Final crumb: the product itself (no url - it is the current page).
        $crumbs[] = ['name' => (string) $product->getName(), 'url' => ''];

        return $crumbs;
    }
}