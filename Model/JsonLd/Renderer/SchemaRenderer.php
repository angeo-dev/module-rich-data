<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Renderer;

use Angeo\RichData\Api\Data\SchemaInterface;
use Angeo\RichData\Model\Config\Source\OutputMode;
use Angeo\RichData\Model\JsonLd\JsonEncoder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Collects every applicable builder and writes the result into the page.
 *
 * Graph mode (default since 2.0.0) merges all nodes into one
 * {"@context": "https://schema.org", "@graph": [...]} document. Nodes carry an
 * @id, so Product, Organization and BreadcrumbList are visibly parts of the same
 * page entity instead of three unrelated blobs.
 *
 * Legacy mode reproduces the 1.x output: one script tag per builder, each with
 * its own @context.
 */
class SchemaRenderer
{
    private const CONTEXT = 'https://schema.org';

    private const XML_PATH_OUTPUT_MODE = 'angeo_rich_data/general/output_mode';

    /** @param SchemaInterface[] $builders */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly array $builders = [],
    ) {
    }

    /**
     * Render all applicable schemas for the given context.
     */
    public function render(StoreInterface $store, array $context = []): string
    {
        $nodes = $this->collect($store, $context);
        if ($nodes === []) {
            return '';
        }

        return $this->isGraphMode($store)
            ? $this->renderGraph($nodes)
            : $this->renderSeparate($nodes);
    }

    /**
     * Render a single specific schema type. Always emits its own script tag.
     */
    public function renderType(string $type, StoreInterface $store, array $context = []): string
    {
        foreach ($this->builders as $builder) {
            if (!($builder instanceof SchemaInterface) || $builder->getType() !== $type) {
                continue;
            }
            if (!$builder->isEnabled($store)) {
                return '';
            }

            $schema = $this->buildSafely($builder, $store, $context);
            if ($schema === null) {
                return '';
            }

            return $this->renderSeparate([$schema]);
        }

        return '';
    }

    /**
     * Return the raw schema nodes without any HTML. Used by the CLI validator
     * and available to anything that needs the data rather than the markup.
     *
     * @return array<int, array>
     */
    public function collect(StoreInterface $store, array $context = []): array
    {
        $nodes = [];

        foreach ($this->builders as $builder) {
            if (!($builder instanceof SchemaInterface)) {
                continue;
            }
            if (!$builder->isEnabled($store)) {
                continue;
            }

            $schema = $this->buildSafely($builder, $store, $context);
            if ($schema !== null) {
                $nodes[] = $schema;
            }
        }

        return $nodes;
    }

    public function isGraphMode(StoreInterface $store): bool
    {
        $mode = (string) $this->scopeConfig->getValue(
            self::XML_PATH_OUTPUT_MODE,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );

        return $mode !== OutputMode::MODE_LEGACY;
    }

    private function buildSafely(SchemaInterface $builder, StoreInterface $store, array $context): ?array
    {
        try {
            $schema = $builder->build($store, $context);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[Angeo RichData] Builder %s failed: %s',
                get_class($builder),
                $e->getMessage()
            ));

            return null;
        }

        if ($schema === null || $schema === []) {
            return null;
        }

        return $schema;
    }

    /**
     * @param array<int, array> $nodes
     */
    private function renderGraph(array $nodes): string
    {
        $graph = [];
        foreach ($nodes as $node) {
            unset($node['@context']);
            $graph[] = $node;
        }

        $document = [
            '@context' => self::CONTEXT,
            '@graph'   => $graph,
        ];

        return $this->encodeToScript($document);
    }

    /**
     * @param array<int, array> $nodes
     */
    private function renderSeparate(array $nodes): string
    {
        $output = '';
        foreach ($nodes as $node) {
            if (!isset($node['@context'])) {
                $node = ['@context' => self::CONTEXT] + $node;
            }
            $output .= $this->encodeToScript($node);
        }

        return $output;
    }

    private function encodeToScript(array $document): string
    {
        try {
            return $this->jsonEncoder->toScript($this->jsonEncoder->encode($document));
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] JSON encoding failed: ' . $e->getMessage());

            return '';
        }
    }
}
