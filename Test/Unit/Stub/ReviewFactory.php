<?php
/**
 * Stand-in for the factory Magento generates at runtime. Unit tests run
 * without generated code, so ProductBuilderTest loads this when the real
 * class is missing.
 */
declare(strict_types=1);

namespace Magento\Review\Model;

class ReviewFactory
{
    /**
     * Create a review model.
     *
     * @param array<string, mixed> $data
     * @return Review|null
     */
    public function create(array $data = [])
    {
        return null;
    }
}
