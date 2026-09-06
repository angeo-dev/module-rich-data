<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ItemCondition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'NewCondition',         'label' => __('New')],
            ['value' => 'UsedCondition',         'label' => __('Used')],
            ['value' => 'RefurbishedCondition',  'label' => __('Refurbished')],
            ['value' => 'DamagedCondition',      'label' => __('Damaged')],
        ];
    }
}
