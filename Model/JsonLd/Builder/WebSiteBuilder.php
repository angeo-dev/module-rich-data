<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\IdFactory;
use Angeo\RichData\Model\Page\CurrentEntity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds the WebSite node, homepage only.
 *
 * Note on SearchAction: Google removed the sitelinks search box from results
 * globally on 21 November 2024. The markup is harmless and WebSite itself is
 * still read for the site-name feature, so the option stays — but it is off by
 * default since 2.0.0 and no longer advertised as a Google feature.
 */
class WebSiteBuilder extends AbstractBuilder
{
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly IdFactory $idFactory,
    ) {
        parent::__construct($scopeConfig);
    }

    public function getType(): string
    {
        return 'website';
    }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/website/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        if (($context['page_type'] ?? '') !== CurrentEntity::PAGE_HOME) {
            return null;
        }

        $baseUrl = $this->idFactory->base($store);

        $schema = [
            '@context'  => 'https://schema.org',
            '@type'     => 'WebSite',
            '@id'       => $this->idFactory->website($store),
            'name'      => (string) $store->getName(),
            'url'       => $baseUrl,
            'publisher' => $this->idFactory->ref($this->idFactory->organization($store)),
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
