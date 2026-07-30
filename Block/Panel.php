<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Block;

use Magento\Framework\View\Element\Template;

/**
 * A template with data on it and nothing else.
 *
 * Every panel is a different arrangement of arrays the collectors already produced, so there
 * is no per-panel block class to write — the controller builds the data, this renders it,
 * and adding a tab means adding a template.
 */
class Panel extends Template
{
    /**
     * @return array<string, mixed>
     */
    public function rows(string $key): array
    {
        $value = $this->getData($key);

        return is_array($value) ? $value : [];
    }

    public function value(string $path, mixed $default = null): mixed
    {
        $current = $this->getData();

        foreach (explode('/', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Milliseconds, rendered so a column of them lines up and reads at a glance.
     */
    public function ms(mixed $value): string
    {
        $ms = (float)$value;

        return $ms >= 1000
            ? number_format($ms / 1000, 2) . ' s'
            : number_format($ms, $ms < 10 ? 2 : 1);
    }

    public function bytes(mixed $value): string
    {
        $bytes = (float)$value;

        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 1) . ' KB',
            default => number_format($bytes) . ' B',
        };
    }

    /**
     * Class name for a duration, so slow rows are visible without reading the numbers.
     */
    public function heat(mixed $value, float $threshold): string
    {
        $ms = (float)$value;

        return match (true) {
            $ms >= $threshold * 4 => 'mdx-bad',
            $ms >= $threshold => 'mdx-warn',
            default => '',
        };
    }
}
