<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * schema.org ReturnFeesEnumeration values for MerchantReturnPolicy.returnFees.
 */
class ReturnFee implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'FreeReturn',            'label' => __('Free return')],
            ['value' => 'ReturnShippingFees',    'label' => __('Customer pays return shipping')],
            ['value' => 'RestockingFees',        'label' => __('Restocking fee')],
        ];
    }
}
