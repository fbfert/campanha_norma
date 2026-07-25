<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuditLogger
{
    /** @var array<int, string> */
    private array $sensitiveKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'api_token',
        'secret',
        'session',
    ];

    public function log(
        string $action,
        ?string $description = null,
        ?Model $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $user = null,
        ?Request $request = null
    ): AuditLog {
        $request ??= request();
        $user ??= $request->user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'description' => $description,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($this->sensitiveKeys as $key) {
            Arr::forget($values, $key);
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && str_contains(strtolower($key), 'password')) {
                unset($values[$key]);
            }
        }

        return $values;
    }
}
