<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds WebSite JSON-LD schema with SearchAction (Sitelinks Searchbox).
 * Injected on homepage only.
 */
class WebSiteBuilder extends AbstractBuilder
{
    public function getType(): string { return 'website'; }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/website/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        // Only inject on homepage
        $pageType = $context['page_type'] ?? '';
        if ($pageType !== 'cms_index_index') {
            return null;
        }

        $baseUrl = rtrim($store->getBaseUrl(), '/');

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $store->getName(),
            'url'      => $baseUrl,
        ];

        if ($this->isConfigEnabled('angeo_rich_data/website/include_searchaction', $store)) {
            $schema['potentialAction'] = [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $baseUrl . '/catalogsearch/result/?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $schema;
    }
}
