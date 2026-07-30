<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed access to everything under the modracx_fdt config section.
 *
 * Every reader on this class is called from a plugin that may run hundreds of times in a
 * single request, so each value is resolved once and kept.
 */
class Config
{
    private const PATH = 'modracx_fdt/';

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->flag('general/enabled');
    }

    public function allowsProduction(): bool
    {
        return $this->flag('general/allow_production');
    }

    public function requiresCookie(): bool
    {
        return $this->flag('general/require_cookie');
    }

    /**
     * @return string[]
     */
    public function allowedIps(): array
    {
        return $this->cache['ips'] ??= array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', (string)$this->value('general/allowed_ips')) ?: []
        )));
    }

    public function collects(string $collector): bool
    {
        return $this->flag('collectors/' . $collector);
    }

    public function threshold(string $name): float
    {
        return (float)($this->cache['t/' . $name] ??= (float)$this->value('thresholds/' . $name));
    }

    public function persists(): bool
    {
        return $this->flag('storage/persist');
    }

    public function retentionHours(): int
    {
        return max(1, (int)$this->value('storage/retention_hours'));
    }

    /**
     * Hard cap on how many entries a single collector may hold.
     *
     * Without it, a page that runs 400k queries takes the whole request down with it while
     * we are trying to explain why it is slow.
     */
    public function maxEntries(): int
    {
        return max(100, (int)$this->value('storage/max_events'));
    }

    private function flag(string $path): bool
    {
        return (bool)($this->cache['f/' . $path] ??= $this->scopeConfig->isSetFlag(
            self::PATH . $path,
            ScopeInterface::SCOPE_STORE
        ));
    }

    private function value(string $path): mixed
    {
        return $this->cache['v/' . $path] ??= $this->scopeConfig->getValue(
            self::PATH . $path,
            ScopeInterface::SCOPE_STORE
        );
    }
}
