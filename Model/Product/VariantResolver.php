<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Psr\Log\LoggerInterface;

/**
 * Extracts the child products of a configurable parent, plus the attributes the
 * children vary by.
 *
 * Feeds the ProductGroup / hasVariant / variesBy markup that Google has
 * supported since February 2024 and that the AI shopping surfaces use to tell
 * "one product in six sizes" apart from "six products".
 *
 * The configurable type is reached through the product's type instance and
 * guarded with method_exists, so a store without Magento_ConfigurableProduct
 * simply gets no variants instead of a fatal error.
 */
class VariantResolver
{
    public const TYPE_CONFIGURABLE = 'configurable';

    /** Hard cap so a 300-variant parent cannot bloat the page. */
    public const MAX_VARIANTS = 50;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigurable(ProductInterface $product): bool
    {
        return $product->getTypeId() === self::TYPE_CONFIGURABLE;
    }

    /**
     * @return ProductInterface[]
     */
    public function getVariants(ProductInterface $product): array
    {
        if (!$this->isConfigurable($product)) {
            return [];
        }

        try {
            if (!method_exists($product, 'getTypeInstance')) {
                return [];
            }

            $typeInstance = $product->getTypeInstance();
            if (!method_exists($typeInstance, 'getUsedProducts')) {
                return [];
            }

            $children = $typeInstance->getUsedProducts($product);
            if (!is_array($children) && !($children instanceof \Traversable)) {
                return [];
            }

            $variants = [];
            foreach ($children as $child) {
                $variants[] = $child;
                if (count($variants) >= self::MAX_VARIANTS) {
                    break;
                }
            }

            return $variants;
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Variant resolution failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * schema.org property names the variants vary by, e.g. ['color', 'size'].
     *
     * @return string[]
     */
    public function getVariesBy(ProductInterface $product): array
    {
        if (!$this->isConfigurable($product)) {
            return [];
        }

        try {
            if (!method_exists($product, 'getTypeInstance')) {
                return [];
            }

            $typeInstance = $product->getTypeInstance();
            if (!method_exists($typeInstance, 'getConfigurableAttributesAsArray')) {
                return [];
            }

            $variesBy = [];
            foreach ($typeInstance->getConfigurableAttributesAsArray($product) as $attribute) {
                $code = (string) ($attribute['attribute_code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $variesBy[] = $this->mapAttributeToSchemaProperty($code);
            }

            return array_values(array_unique(array_filter($variesBy)));
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] variesBy resolution failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Map a Magento attribute code onto a schema.org property where one exists.
     * Unknown codes are passed through unchanged, which is what schema.org
     * expects for custom variant dimensions.
     */
    private function mapAttributeToSchemaProperty(string $attributeCode): string
    {
        return match ($attributeCode) {
            'color'    => 'color',
            'size'     => 'size',
            'material' => 'material',
            'pattern'  => 'pattern',
            default    => $attributeCode,
        };
    }
}
