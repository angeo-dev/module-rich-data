<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Renderer;

use Angeo\RichData\Api\Data\SchemaInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders all applicable schema builders to <script type="application/ld+json"> tags.
 *
 * Builders are injected via di.xml. Each builder decides whether to output
 * anything based on the context (product page, CMS page, homepage, etc.).
 *
 * Usage in Block or Observer:
 *   $html = $this->schemaRenderer->render($store, ['product' => $product, 'page_type' => 'catalog_product_view']);
 *   $page->getLayout()->getBlock('head.additional')->setData('rich_data_scripts', $html);
 */
class SchemaRenderer
{
    /** @param SchemaInterface[] $builders */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array           $builders = [],
    ) {}

    /**
     * Render all applicable schemas for the given context.
     * Returns concatenated <script type="application/ld+json"> HTML.
     */
    public function render(StoreInterface $store, array $context = []): string
    {
        $output = '';

        foreach ($this->builders as $builder) {
            if (!($builder instanceof SchemaInterface)) {
                continue;
            }
            if (!$builder->isEnabled($store)) {
                continue;
            }

            try {
                $schema = $builder->build($store, $context);
                if ($schema === null || empty($schema)) {
                    continue;
                }

                $json = json_encode(
                    $schema,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                );

                $output .= sprintf(
                    "\n<script type=\"application/ld+json\">\n%s\n</script>",
                    $json
                );

            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    '[Angeo RichData] Builder %s failed: %s',
                    get_class($builder),
                    $e->getMessage()
                ));
            }
        }

        return $output;
    }

    /**
     * Render a single specific schema type.
     */
    public function renderType(string $type, StoreInterface $store, array $context = []): string
    {
        foreach ($this->builders as $builder) {
            if ($builder instanceof SchemaInterface && $builder->getType() === $type) {
                if (!$builder->isEnabled($store)) {
                    return '';
                }
                try {
                    $schema = $builder->build($store, $context);
                    if ($schema === null) {
                        return '';
                    }
                    $json = json_encode(
                        $schema,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                    );
                    return sprintf("\n<script type=\"application/ld+json\">\n%s\n</script>", $json);
                } catch (\Throwable $e) {
                    $this->logger->error('[Angeo RichData] renderType failed: ' . $e->getMessage());
                    return '';
                }
            }
        }
        return '';
    }
}
