<?php

declare(strict_types=1);

namespace Angeo\RichData\Api\Data;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Contract for all JSON-LD schema builders.
 *
 * Each builder owns one schema type (Product, Organization, ...) and returns a
 * PHP array. The renderer encodes it and injects it into the page, either as a
 * single @graph document (default since 2.0.0) or as one script tag per builder
 * (legacy mode).
 *
 * Builders SHOULD set an '@id' on their root node so that nodes can reference
 * each other. They MAY set '@context'; the renderer strips it in graph mode.
 *
 * Register via di.xml to add custom schema types:
 *
 *   <type name="Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer">
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
     * @param array $context Additional context (product, category, page type, ...)
     * @return array|null Schema array, or NULL if this builder has nothing to output here
     */
    public function build(StoreInterface $store, array $context = []): ?array;

    /**
     * Whether this builder is enabled for the given store.
     */
    public function isEnabled(StoreInterface $store): bool;
}
