<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds Organization JSON-LD schema injected on every page.
 * Tells AI crawlers who runs the store — improves brand citation.
 */
class OrganizationBuilder extends AbstractBuilder
{
    public function getType(): string { return 'organization'; }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/organization/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        $name = $this->getConfig('angeo_rich_data/organization/name', $store)
            ?: $store->getName();

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $name,
            'url'      => rtrim($store->getBaseUrl(), '/'),
        ];

        $logo = $this->getConfig('angeo_rich_data/organization/logo', $store);
        if ($logo) {
            $schema['logo'] = [
                '@type'       => 'ImageObject',
                'url'         => $logo,
                'contentUrl'  => $logo,
            ];
        }

        // sameAs — comma-separated social URLs
        $sameAs = $this->getConfig('angeo_rich_data/organization/same_as', $store);
        if ($sameAs) {
            $urls = array_values(array_filter(array_map('trim', explode(',', $sameAs))));
            if (!empty($urls)) {
                $schema['sameAs'] = $urls;
            }
        }

        // ContactPoint
        $phone       = $this->getConfig('angeo_rich_data/organization/contact_telephone', $store);
        $contactType = $this->getConfig('angeo_rich_data/organization/contact_type', $store);
        if ($phone) {
            $schema['contactPoint'] = [
                '@type'       => 'ContactPoint',
                'telephone'   => $phone,
                'contactType' => $contactType ?: 'customer service',
            ];
        }

        return $schema;
    }
}
