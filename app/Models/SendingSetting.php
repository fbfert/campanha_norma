<?php

namespace App\Models;

use App\Enums\RetryBackoffType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SendingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'max_per_minute',
        'max_per_hour',
        'max_per_day',
        'unanswered_lock_threshold',
        'minimum_interval_seconds',
        'start_time',
        'end_time',
        'allowed_weekdays',
        'timezone',
        'max_attempts',
        'retry_interval_minutes',
        'retry_backoff_type',
        'pause_when_disconnected',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'retry_backoff_type' => RetryBackoffType::class,
            'pause_when_disconnected' => 'boolean',
            'unanswered_lock_threshold' => 'integer',
        ];
    }

    /**
     * Dias permitidos, sempre como números.
     *
     * O formulário envia `"5"` e o cast `array` devolvia exatamente isso: texto.
     * A janela de envio compara com `in_array($agora->dayOfWeekIso, ..., true)`,
     * e `5 !== '5'` — então **nenhum dia era permitido**, em nenhum lote. Todo
     * destinatário parava em "Dia não permitido para envio", e a causa não
     * aparecia em lugar nenhum: a tela mostrava os dias marcados corretamente.
     *
     * A correção fica aqui, e não afrouxando a comparação. Comparação estrita e
     * proteção, não o defeito; o defeito era o tipo chegar errado. Normalizando
     * na fronteira, todos os consumidores passam a receber número — inclusive
     * as linhas já gravadas com texto, que voltam corrigidas na leitura.
     */
    protected function allowedWeekdays(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $this->comoDias(json_decode((string) $value, true)),
            set: fn (mixed $value): string => (string) json_encode($this->comoDias($value)),
        );
    }

    /**
     * @return list<int>
     */
    private function comoDias(mixed $value): array
    {
        return array_values(array_unique(array_map(
            static fn (mixed $dia): int => (int) $dia,
            array_filter((array) $value, static fn (mixed $dia): bool => is_numeric($dia))
        )));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
