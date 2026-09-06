<?php

declare(strict_types=1);

namespace Angeo\RichData\ViewModel;

use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Angeo\RichData\Model\Page\BreadcrumbTrailBuilder;
use Angeo\RichData\Model\Page\CategoryProductsProvider;
use Angeo\RichData\Model\Page\CurrentEntity;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Assembles the render context and hands it to the renderer.
 *
 * The 1.x version returned an empty string as soon as a product or category was
 * missing from the registry, which killed the Organization and WebSite nodes on
 * that page too. Missing entities now simply leave their context key unset.
 */
class JsonLd implements ArgumentInterface
{
    public function __construct(
        private readonly SchemaRenderer $schemaRenderer,
        private readonly StoreManagerInterface $storeManager,
        private readonly CurrentEntity $currentEntity,
        private readonly BreadcrumbTrailBuilder $breadcrumbTrailBuilder,
        private readonly CategoryProductsProvider $categoryProductsProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Concatenated <script type="application/ld+json"> markup for this page.
     */
    public function getSchemaHtml(): string
    {
        try {
            $store = $this->storeManager->getStore();

            return $this->schemaRenderer->render($store, $this->buildContext($store));
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] ViewModel failed: ' . $e->getMessage());

            return '';
        }
    }

    public function buildContext(StoreInterface $store): array
    {
        $pageType = $this->currentEntity->getPageType();
        $baseUrl  = rtrim((string) $store->getBaseUrl(), '/');

        $context = [
            'page_type' => $pageType,
            'page_url'  => $baseUrl,
        ];

        switch ($pageType) {
            case CurrentEntity::PAGE_PRODUCT:
                $product = $this->currentEntity->getProduct($store);
                if ($product !== null) {
                    $context['product']     = $product;
                    $context['page_url']    = method_exists($product, 'getProductUrl')
                        ? (string) $product->getProductUrl()
                        : $baseUrl;
                    $context['breadcrumbs'] = $this->breadcrumbTrailBuilder->forProduct($product, $store);
                }
                break;

            case CurrentEntity::PAGE_CATEGORY:
                $category = $this->currentEntity->getCategory($store);
                if ($category !== null) {
                    $listingPage = $this->currentEntity->getListingPage();
                    $categoryUrl = (string) ($category->getUrl() ?: $baseUrl);
                    if ($listingPage > 1) {
                        $categoryUrl .= (str_contains($categoryUrl, '?') ? '&' : '?') . 'p=' . $listingPage;
                    }

                    $context['category']          = $category;
                    $context['page_url']          = $categoryUrl;
                    $context['category_products'] = $this->categoryProductsProvider->getProducts($category, $store);
                    $context['breadcrumbs']       = $this->breadcrumbTrailBuilder->forCategory($category, $store);
                }
                break;

            case CurrentEntity::PAGE_CMS:
                $page = $this->currentEntity->getCmsPage();
                if ($page !== null) {
                    $identifier = (string) $page->getIdentifier();
                    $context['cms_page_identifier'] = $identifier;
                    $context['cms_page_content']    = (string) $page->getContent();
                    $context['page_url']            = $baseUrl . '/' . ltrim($identifier, '/');
                    $context['breadcrumbs']         = $this->breadcrumbTrailBuilder->forCmsPage(
                        (string) $page->getTitle(),
                        $store
                    );
                }
                break;

            case CurrentEntity::PAGE_HOME:
                // The homepage is deliberately not treated as a FAQ candidate.
                $context['page_url'] = $baseUrl;
                break;
        }

        return $context;
    }
}
