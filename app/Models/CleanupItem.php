<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um registro que uma limpeza tirou do ar.
 *
 * `record_table` e `record_id` apontam para a linha original. Guardar a tabela
 * como texto, e não uma classe de modelo, e proposital: o nome da tabela e o
 * que continua verdadeiro se a classe for renomeada, e a lixeira precisa
 * conseguir descrever o que removeu mesmo anos depois.
 *
 * `restore_payload` atende os casos que não são exclusão suave. Etiqueta de
 * contato mora numa tabela de ligação sem `deleted_at`, e a linha da planilha
 * importada continua existindo — o que sai ali e o vínculo. Nesses dois, o que
 * permite desfazer e o valor antigo guardado aqui.
 */
class CleanupItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleanup_operation_id',
        'target',
        'record_table',
        'record_id',
        'summary',
        'restore_payload',
        'restored_at',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'restore_payload' => 'array',
            'restored_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CleanupOperation::class, 'cleanup_operation_id');
    }
}
