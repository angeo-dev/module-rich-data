<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds BreadcrumbList JSON-LD schema.
 *
 * Context keys:
 *   'breadcrumbs' => array of ['name' => string, 'url' => string]
 */
class BreadcrumbBuilder extends AbstractBuilder
{
    public function getType(): string { return 'breadcrumb'; }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/product/include_breadcrumb';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        $breadcrumbs = $context['breadcrumbs'] ?? [];
        if (empty($breadcrumbs)) {
            return null;
        }

        $items = [];
        foreach ($breadcrumbs as $position => $crumb) {
            $item = [
                '@type'    => 'ListItem',
                'position' => $position + 1,
                'name'     => $crumb['name'],
            ];
            if (!empty($crumb['url'])) {
                $item['item'] = $crumb['url'];
            }
            $items[] = $item;
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
