<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\IdFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds the BreadcrumbList node.
 *
 * Since 2.0.0 it runs on product, category and CMS pages, not only product
 * pages, and it has its own configuration group. The old
 * product/include_breadcrumb flag is still honoured so a store that switched
 * breadcrumbs off in 1.x keeps them off after the upgrade.
 *
 * Context keys:
 *   'breadcrumbs' => array<int, array{name: string, url: string}>
 *   'page_url'    => string
 */
class BreadcrumbBuilder extends AbstractBuilder
{
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly IdFactory $idFactory,
    ) {
        parent::__construct($scopeConfig);
    }

    public function getType(): string
    {
        return 'breadcrumb';
    }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/breadcrumb/enabled';
    }

    public function isEnabled(StoreInterface $store): bool
    {
        // Legacy 1.x switch kept as a veto so upgrades do not silently turn
        // breadcrumbs back on.
        return parent::isEnabled($store)
            && $this->isConfigEnabled('angeo_rich_data/product/include_breadcrumb', $store);
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        $breadcrumbs = $context['breadcrumbs'] ?? [];
        if (!is_array($breadcrumbs) || count($breadcrumbs) < 2) {
            return null;
        }

        $items    = [];
        $position = 1;

        foreach ($breadcrumbs as $crumb) {
            $name = trim((string) ($crumb['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $item = [
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => $name,
            ];

            $url = trim((string) ($crumb['url'] ?? ''));
            if ($url !== '') {
                $item['item'] = $url;
            }

            $items[] = $item;
            $position++;
        }

        if (count($items) < 2) {
            return null;
        }

        $pageUrl = (string) ($context['page_url'] ?? $this->idFactory->base($store));

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            '@id'             => $this->idFactory->forPage($pageUrl, IdFactory::FRAGMENT_BREADCRUMB),
            'itemListElement' => $items,
        ];
    }
}
