<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds the stable @id values that link graph nodes together.
 *
 * Page-scoped nodes hang off the current page URL, site-scoped nodes off the
 * store base URL, so the same Organization node keeps one identity across every
 * page of the store.
 */
class IdFactory
{
    public const FRAGMENT_PRODUCT     = 'product';
    public const FRAGMENT_PRODUCT_GRP = 'productgroup';
    public const FRAGMENT_BREADCRUMB  = 'breadcrumb';
    public const FRAGMENT_COLLECTION  = 'collection';
    public const FRAGMENT_FAQ         = 'faq';
    public const FRAGMENT_ORG         = 'organization';
    public const FRAGMENT_WEBSITE     = 'website';

    public function base(StoreInterface $store): string
    {
        return rtrim((string) $store->getBaseUrl(), '/');
    }

    public function organization(StoreInterface $store): string
    {
        return $this->base($store) . '/#' . self::FRAGMENT_ORG;
    }

    public function website(StoreInterface $store): string
    {
        return $this->base($store) . '/#' . self::FRAGMENT_WEBSITE;
    }

    /**
     * Page-scoped id: strips any existing fragment or query string first so the
     * same page always yields the same identifier.
     */
    public function forPage(string $pageUrl, string $fragment): string
    {
        $clean = strtok($pageUrl, '#');
        $clean = is_string($clean) ? $clean : $pageUrl;

        return rtrim($clean, '/') . '#' . $fragment;
    }

    /**
     * Shorthand for a node that only references another node.
     */
    public function ref(string $id): array
    {
        return ['@id' => $id];
    }
}
