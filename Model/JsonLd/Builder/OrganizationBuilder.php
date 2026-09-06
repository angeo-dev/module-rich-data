<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\IdFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds the Organization node injected on every page.
 *
 * Its @id is store-wide rather than page-scoped, so every Offer that names this
 * store as seller points at one and the same brand entity.
 */
class OrganizationBuilder extends AbstractBuilder
{
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly IdFactory $idFactory,
    ) {
        parent::__construct($scopeConfig);
    }

    public function getType(): string
    {
        return 'organization';
    }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/organization/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        $name = $this->getConfig('angeo_rich_data/organization/name', $store) ?: (string) $store->getName();

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => $this->idFactory->organization($store),
            'name'     => $name,
            'url'      => $this->idFactory->base($store),
        ];

        $description = $this->getConfig('angeo_rich_data/organization/description', $store);
        if ($description !== '') {
            $schema['description'] = $description;
        }

        $logo = $this->getConfig('angeo_rich_data/organization/logo', $store);
        if ($logo !== '') {
            $schema['logo'] = [
                '@type'      => 'ImageObject',
                'url'        => $logo,
                'contentUrl' => $logo,
            ];
        }

        $sameAs = $this->toList($this->getConfig('angeo_rich_data/organization/same_as', $store));
        if ($sameAs !== []) {
            $schema['sameAs'] = $sameAs;
        }

        $phone = $this->getConfig('angeo_rich_data/organization/contact_telephone', $store);
        if ($phone !== '') {
            $schema['contactPoint'] = [
                '@type'       => 'ContactPoint',
                'telephone'   => $phone,
                'contactType' => $this->getConfig('angeo_rich_data/organization/contact_type', $store)
                    ?: 'customer service',
            ];
        }

        return $schema;
    }
}
