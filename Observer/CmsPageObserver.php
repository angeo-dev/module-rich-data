<?php

declare(strict_types=1);

namespace Angeo\RichData\Observer;

use Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer;
use Magento\Cms\Model\Page as CmsPage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Injects Organization, WebSite (homepage only), and FAQPage JSON-LD on CMS pages.
 *
 * Listens to: layout_render_before_cms_page_view and layout_render_before_cms_index_index
 */
class CmsPageObserver implements ObserverInterface
{
    public function __construct(
        private readonly SchemaRenderer        $schemaRenderer,
        private readonly StoreManagerInterface $storeManager,
        private readonly Registry              $registry,
    ) {}

    public function execute(Observer $observer): void
    {
        try {
            $store    = $this->storeManager->getStore();
            $eventName = $observer->getEvent()->getName();

            $pageType = str_replace('layout_render_before_', '', $eventName);

            /** @var CmsPage|null $cmsPage */
            $cmsPage = $this->registry->registry('cms_page');
            $content = $cmsPage ? (string) $cmsPage->getContent() : '';

            $context = [
                'page_type'        => $pageType,
                'cms_page_content' => $content,
            ];

            $html = $this->schemaRenderer->render($store, $context);
            if (!$html) {
                return;
            }

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
