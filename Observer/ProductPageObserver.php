<?php

declare(strict_types=1);

namespace Angeo\RichData\Observer;

use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Injects Product and BreadcrumbList JSON-LD on catalog_product_view pages.
 *
 * Listens to: layout_render_before_catalog_product_view
 * Injects via: PageConfig::addRemotePageAsset with type 'script'
 * — this places the <script> in <head> before </head>.
 */
class ProductPageObserver implements ObserverInterface
{
    public function __construct(
        private readonly SchemaRenderer      $schemaRenderer,
        private readonly StoreManagerInterface $storeManager,
        private readonly Registry            $registry,
        private readonly PageConfig          $pageConfig,
    ) {}

    public function execute(Observer $observer): void
    {
        try {
            /** @var Product|null $product */
            $product = $this->registry->registry('current_product');
            if (!$product || !$product->getId()) {
                return;
            }

            $store = $this->storeManager->getStore();

            $context = [
                'product'   => $product,
                'page_type' => 'catalog_product_view',
            ];

            $html = $this->schemaRenderer->render($store, $context);
            if (!$html) {
                return;
            }

            // Inject via head block additional scripts
            $layout = $observer->getData('layout');
            if ($layout) {
                $headBlock = $layout->getBlock('head.additional');
                if ($headBlock) {
                    $existing = $headBlock->getData('rich_data_scripts') ?? '';
                    $headBlock->setData('rich_data_scripts', $existing . $html);
                }
            }

        } catch (\Throwable $e) {
            // Never break the page
        }
    }
}
