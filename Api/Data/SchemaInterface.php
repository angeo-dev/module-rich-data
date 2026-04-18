<?php

declare(strict_types=1);

namespace Angeo\RichData\Api\Data;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Contract for all JSON-LD schema builders.
 *
 * Each builder is responsible for one schema type (Product, Organization, etc.)
 * and returns a PHP array that is JSON-encoded and injected into the page.
 *
 * Register via di.xml to add custom schema types:
 *
 *   <type name="Angeo\RichData\Model\JsonLd\Renderer\HeadRenderer">
 *     <arguments><argument name="builders" xsi:type="array">
 *       <item name="my_schema" xsi:type="object">Vendor\Module\Model\JsonLd\Builder\MySchemaBuilder</item>
 *     </argument></arguments>
 *   </type>
 */
interface SchemaInterface
{
    /**
     * Machine-readable schema type identifier.
     * Examples: 'product', 'organization', 'website', 'faq'
     */
    public function getType(): string;

    /**
     * Build the schema array for a given context.
     *
     * @param StoreInterface $store   Current store
     * @param array          $context Additional context (product, page, etc.)
     * @return array|null  Schema array, or NULL if this builder has nothing to output in this context
     */
    public function build(StoreInterface $store, array $context = []): ?array;

    /**
     * Whether this builder is enabled for the given store.
     */
    public function isEnabled(StoreInterface $store): bool;
}
