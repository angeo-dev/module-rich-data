<?php

declare(strict_types=1);

namespace Angeo\RichData\ViewModel;

use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page as CmsPage;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * ViewModel for JSON-LD schema injection.
 * Injected into head_scripts.phtml via layout XML argument.
 */
class JsonLd implements ArgumentInterface
{
    public function __construct(
        private readonly SchemaRenderer        $schemaRenderer,
        private readonly StoreManagerInterface $storeManager,
        private readonly Registry              $registry,
        private readonly HttpRequest           $request,
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
            }

            if (in_array($pageType, ['cms_index_index', 'cms_page_view'], true)) {
                /** @var CmsPage|null $cmsPage */
                $cmsPage = $this->registry->registry('cms_page');
                $context['cms_page_content'] = $cmsPage ? (string) $cmsPage->getContent() : '';
            }

            return $this->schemaRenderer->render($store, $context);

        } catch (\Throwable) {
            return '';
        }
    }
}
