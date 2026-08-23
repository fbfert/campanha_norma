<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Construtor de consultas que mantem `cleanup_trash_key` na exclusão em massa.
 *
 * `Modelo::where(...)->delete()` não dispara evento de modelo nenhum: o Eloquent
 * monta um `UPDATE` direto e nunca carrega as linhas como modelo. Sem este construtor, a
 * chave ficaria em 0 numa linha que está na lixeira, e o índice único passaria
 * a recusar a próxima inscrição da mesma pessoa na mesma campanha — um defeito
 * que só apareceria semanas depois da limpeza, longe da causa.
 *
 * Isto não e detalhe da Limpeza: vale para qualquer código que exclua estes
 * modelos, hoje ou depois. Deixar a garantia dependendo de quem chama seria
 * escrever a regra num documento em vez de no sistema.
 */
class ConstrutorComChaveDeLixeira extends Builder
{
    /**
     * @return int|mixed
     */
    public function delete()
    {
        $modelo = $this->getModel();

        if (! method_exists($modelo, 'getDeletedAtColumn')) {
            return parent::delete();
        }

        return $this->toBase()->update([
            $modelo->getDeletedAtColumn() => $modelo->freshTimestampString(),
            'cleanup_trash_key' => new Expression($this->getGrammar()->wrap($modelo->getKeyName())),
        ]);
    }

    /**
     * @return int
     */
    public function restore()
    {
        $modelo = $this->getModel();

        return $this->toBase()->update([
            $modelo->getDeletedAtColumn() => null,
            'cleanup_trash_key' => 0,
        ]);
    }
}
