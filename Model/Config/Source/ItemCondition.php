<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ItemCondition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'NewCondition',         'label' => 'New'],
            ['value' => 'UsedCondition',         'label' => 'Used'],
            ['value' => 'RefurbishedCondition',  'label' => 'Refurbished'],
            ['value' => 'DamagedCondition',      'label' => 'Damaged'],
        ];
    }
}
