<?php
declare(strict_types=1);

namespace Angeo\RichData\ViewModel;

use Angeo\RichData\Model\Text\PlainText;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * og:description for product pages (2.1.0).
 *
 * Magento prints the short description as-is, so a product with a short or
 * empty short description gets an empty or two-word card (and AEO Audit's
 * Open Graph signal warns under 50 characters). This picks the meta
 * description, the short description or the description — the first with 50+
 * characters — and keeps it to 160 characters for cards.
 *
 * @since 2.1.0
 */
class OpenGraph implements ArgumentInterface
{
    public const MIN = 50;
    public const MAX = 160;

    public function description(ProductInterface $product): string
    {
        // The EAV getters are magic (__call): read the data, not method_exists().
        $get = static function (string $attribute) use ($product): ?string {
            $value = $product instanceof DataObject ? $product->getData($attribute) : null;

            return is_scalar($value) ? (string) $value : null;
        };

        return self::pickDescription($get('meta_description'), $get('short_description'), $get('description'));
    }

    public static function pickDescription(?string $meta, ?string $short, ?string $long): string
    {
        return PlainText::truncate(PlainText::pick([$meta, $short, $long], self::MIN), self::MAX);
    }
}
