<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SystemSettingService
{
    private const CACHE_KEY = 'system_settings.all';

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return SystemSetting::query()
                ->pluck('value', 'key')
                ->all();
        });
    }

    /**
     * Invalida o cache sem escrever nada.
     *
     * Existe para quem grava configuração por um caminho próprio, com grupo,
     * tipo e visibilidade escolhidos a mão. `updateMany` marca tudo como
     * público e adivinha o tipo, o que serve para a tela geral e não serve
     * para um segredo.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @param array<string, mixed> $values */
    public function updateMany(array $values): array
    {
        $oldValues = SystemSetting::query()
            ->whereIn('key', array_keys($values))
            ->pluck('value', 'key')
            ->all();

        foreach ($values as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'group' => str($key)->before('.')->toString(),
                    'value' => (string) $value,
                    'type' => is_numeric($value) ? 'integer' : 'string',
                    'is_public' => true,
                ]
            );
        }

        Cache::forget(self::CACHE_KEY);

        return $oldValues;
    }
}
