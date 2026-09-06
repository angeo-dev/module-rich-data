<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;

/**
 * Resolves whether a product should be published as in stock.
 *
 * 1.x asked StockRegistryInterface directly, which reports the legacy
 * cataloginventory quantity. On a Multi Source Inventory store with more than
 * one source or a non-default stock that number is not what the storefront
 * shows, so out-of-stock products were published as InStock.
 *
 * Product::isSalable() is the salability answer the storefront itself uses and
 * MSI plugs into it, so it is asked first. The stock registry stays as a
 * fallback for contexts where salability is not resolvable.
 */
class StockResolver
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
    ) {
    }

    public function isInStock(ProductInterface $product, int $storeId): bool
    {
        try {
            if (method_exists($product, 'isSalable')) {
                return (bool) $product->isSalable();
            }
        } catch (\Throwable) {
            // fall through to the legacy stock registry
        }

        try {
            $stockItem = $this->stockRegistry->getStockItem((int) $product->getId(), $storeId);

            return (bool) $stockItem->getIsInStock();
        } catch (\Throwable) {
            return true; // optimistic default, same as 1.x
        }
    }
}
