<?php

namespace App\Support\Modules;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

class ModuleRegistry
{
    public function __construct(
        protected Repository $config,
    ) {}

    public function enabled(string $module): bool
    {
        return (bool) $this->definition($module)['enabled'];
    }

    public function disabled(string $module): bool
    {
        return ! $this->enabled($module);
    }

    public function label(string $module): string
    {
        return $this->definition($module)['label'] ?? $module;
    }

    public function exists(string $module): bool
    {
        return is_array($this->config->get("modules.{$module}"));
    }

    /**
     * @return array<string, array{label: string, phase: int, enabled: bool}>
     */
    public function all(): array
    {
        return $this->config->get('modules', []);
    }

    /**
     * @return array{label: string, phase: int, enabled: bool}
     */
    protected function definition(string $module): array
    {
        if (! $this->exists($module)) {
            throw new InvalidArgumentException("Unknown module [{$module}]. Register it in config/modules.php.");
        }

        return $this->config->get("modules.{$module}");
    }
}
