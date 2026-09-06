<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How the schema nodes are written into the page.
 */
class OutputMode implements OptionSourceInterface
{
    public const MODE_GRAPH  = 'graph';
    public const MODE_LEGACY = 'legacy';

    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::MODE_GRAPH,
                'label' => __('Single @graph document (recommended)'),
            ],
            [
                'value' => self::MODE_LEGACY,
                'label' => __('One script tag per schema (1.x behaviour)'),
            ],
        ];
    }
}
