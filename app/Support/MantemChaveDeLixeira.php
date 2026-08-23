<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Mantem `cleanup_trash_key` em dia nos modelos que têm índice único.
 *
 * A coluna vale 0 enquanto a linha está viva e passa a valer o próprio id
 * quando ela vai para a lixeira. E o que permite o índice único continuar
 * barrando duplicata viva sem barrar quem foi limpo e voltou a participar — o
 * porquê disso está por extenso na migração `create_cleanup_tables`.
 *
 * São dois caminhos de exclusão, e os dois precisam ser cobertos:
 *
 * - `$modelo->delete()` passa por `runSoftDelete`, que grava só `deleted_at` e
 *   `updated_at` e descarta qualquer outro atributo sujo. Por isso a chave vai
 *   num `UPDATE` próprio, disparado pelo evento `deleted`;
 * - `Modelo::where(...)->delete()` não carrega modelo nenhum e não dispara evento
 *   nenhum. Esse caminho e coberto pelo `ConstrutorComChaveDeLixeira`.
 *
 * Cobrir só o primeiro deixaria a garantia valendo conforme o jeito de chamar,
 * que e o tipo de regra que ninguém lembra na hora.
 */
trait MantemChaveDeLixeira
{
    public static function bootMantemChaveDeLixeira(): void
    {
        static::deleted(function (self $modelo): void {
            if (method_exists($modelo, 'isForceDeleting') && $modelo->isForceDeleting()) {
                return;
            }

            $modelo->newQueryWithoutScopes()
                ->toBase()
                ->where($modelo->getKeyName(), $modelo->getKey())
                ->update(['cleanup_trash_key' => $modelo->getKey()]);
        });

        static::restored(function (self $modelo): void {
            $modelo->newQueryWithoutScopes()
                ->toBase()
                ->where($modelo->getKeyName(), $modelo->getKey())
                ->update(['cleanup_trash_key' => 0]);
        });
    }

    public function newEloquentBuilder($query): Builder
    {
        return new ConstrutorComChaveDeLixeira($query);
    }
}
