<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Angeo\RichData\Api\Data\SchemaInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;

abstract class AbstractBuilder implements SchemaInterface
{
    public function __construct(
        protected readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    protected function getConfig(string $path, StoreInterface $store): string
    {
        return trim((string) $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        ));
    }

    protected function isConfigEnabled(string $path, StoreInterface $store): bool
    {
        return $this->scopeConfig->isSetFlag(
            $path,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );
    }

    public function isEnabled(StoreInterface $store): bool
    {
        return $this->isConfigEnabled('angeo_rich_data/general/enabled', $store)
            && $this->isConfigEnabled($this->getEnabledConfigPath(), $store);
    }

    /**
     * Split a comma-separated config value into a clean list.
     *
     * @return string[]
     */
    protected function toList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
    }

    abstract protected function getEnabledConfigPath(): string;
}
