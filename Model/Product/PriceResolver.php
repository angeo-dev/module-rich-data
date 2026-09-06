<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Psr\Log\LoggerInterface;

/**
 * Resolves the price that must appear in the Offer.
 *
 * Google and the AI shopping engines require the marked-up price to match the
 * price the visitor sees. Product::getFinalPrice() does not: it is the raw
 * catalog price with no tax adjustment, so on any store that displays prices
 * including VAT (i.e. most of the EU) 1.x published a number that was visibly
 * lower than the page.
 *
 * The pricing framework's final_price amount already carries the tax adjustment
 * that matches the store's display configuration, so it is used instead. It also
 * exposes a minimum and a maximum, which is what makes configurable, grouped and
 * bundle products work: those have a price range, not a single price, and 1.x
 * flattened the range into whatever getFinalPrice() happened to return.
 */
class PriceResolver
{
    /** Prices closer than this are treated as one price, not a range. */
    private const EPSILON = 0.005;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{min: float, max: float, is_range: bool}
     */
    public function resolve(ProductInterface $product): array
    {
        $min = null;
        $max = null;

        try {
            if (method_exists($product, 'getPriceInfo')) {
                $finalPrice = $product->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE);

                if (method_exists($finalPrice, 'getMinimalPrice')) {
                    $min = (float) $finalPrice->getMinimalPrice()->getValue();
                }
                if (method_exists($finalPrice, 'getMaximalPrice')) {
                    $max = (float) $finalPrice->getMaximalPrice()->getValue();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo RichData] Price resolution failed: ' . $e->getMessage());
        }

        // Fallback for contexts where price info is unavailable.
        if ($min === null || !is_finite($min)) {
            $min = (float) $product->getFinalPrice();
        }
        if ($max === null || !is_finite($max)) {
            $max = $min;
        }

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        return [
            'min'      => $min,
            'max'      => $max,
            'is_range' => ($max - $min) > self::EPSILON,
        ];
    }

    public function format(float $price): string
    {
        return number_format($price, 2, '.', '');
    }
}
