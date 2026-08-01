<?php

namespace App\Services\Ai;

use App\Models\InsightTopic;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importação da taxonomia de temas.
 *
 * Casa pelo identificador: existe, atualiza; não existe, cria. **Nunca apaga.**
 * Um tema que sumiu da planilha continua no sistema, e a tela de temas segue
 * sendo o lugar de excluir, uma linha por vez e com confirmação. Importação que
 * apaga o que não esta no arquivo e como se perde uma taxonomia inteira por
 * causa de um filtro esquecido no Excel.
 *
 * Duas colunas da exportação são lidas e ignoradas de propósito:
 *
 * - `insights` e contagem, calculada a partir das interpretações. Não faria
 *   sentido escrever.
 * - `fallback` decide para onde vai o que o modelo não soube classificar. So
 *   pode haver um, e trocar isso por planilha e alterar o comportamento da
 *   classificação sem passar pela tela que explica o que isso significa.
 */
class InsightTopicImporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Descreve o que cada linha faria, sem gravar nada.
     *
     * @param  list<array{linha: int, dados: array<string, string>}>  $rows
     * @return list<array<string, mixed>>
     */
    public function plan(array $rows): array
    {
        $existing = InsightTopic::query()->pluck('id', 'slug');
        $slugsNoArquivo = [];
        $plan = [];

        foreach ($rows as $row) {
            $data = $row['dados'];
            $name = trim($data['tema'] ?? '');
            $slug = Str::slug(trim($data['identificador'] ?? '') ?: $name, '_');

            $error = match (true) {
                $name === '' => 'Sem o nome do tema.',
                $slug === '' => 'Sem identificador e sem nome de onde derivá-lo.',
                in_array($slug, $slugsNoArquivo, true) => 'Identificador repetido dentro do próprio arquivo.',
                default => null,
            };

            $slugsNoArquivo[] = $slug;

            $plan[] = [
                'linha' => $row['linha'],
                'acao' => $error !== null ? 'erro' : ($existing->has($slug) ? 'atualizar' : 'criar'),
                'motivo' => $error,
                'tema' => $name,
                'identificador' => $slug,
                'tema_pai' => trim($data['tema_pai'] ?? ''),
                'atributos' => [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $this->orNull($data['descricao'] ?? ''),
                    'synonyms' => $this->orNull($data['sinonimos'] ?? ''),
                    'color' => $this->orNull($data['cor'] ?? ''),
                    'display_order' => (int) ($data['ordem'] ?? 0),
                    'is_active' => ! in_array(Str::lower(trim($data['situacao'] ?? 'ativo')), ['inativo', 'nao', 'não', '0'], true),
                ],
            ];
        }

        return $plan;
    }

    /**
     * Aplica o plano.
     *
     * Duas passagens: primeiro todos os temas, depois os vínculos de pai. Um
     * arquivo em que o filho aparece antes do pai e comum — basta alguém
     * ordenar a planilha por nome — e numa passagem so o vínculo se perderia
     * em silêncio.
     *
     * @param  list<array<string, mixed>>  $plan
     * @return array{criados: int, atualizados: int, ignorados: int}
     */
    public function apply(array $plan, User $user): array
    {
        $criados = $atualizados = 0;

        DB::transaction(function () use ($plan, $user, &$criados, &$atualizados): void {
            foreach ($plan as $item) {
                if ($item['acao'] === 'erro') {
                    continue;
                }

                $topic = InsightTopic::query()->where('slug', $item['identificador'])->first();

                if ($topic) {
                    $before = $topic->only(array_keys($item['atributos']));
                    $topic->update($item['atributos'] + ['updated_by' => $user->id]);
                    $this->audit->log('insight_topic.imported_update', 'Tema atualizado por importação.', $topic, $before, $item['atributos'], $user);
                    $atualizados++;

                    continue;
                }

                $topic = InsightTopic::create($item['atributos'] + [
                    'is_fallback' => false,
                    'created_by' => $user->id,
                ]);
                $this->audit->log('insight_topic.imported_create', 'Tema criado por importação.', $topic, null, $item['atributos'], $user);
                $criados++;
            }

            $this->linkParents($plan);
        });

        return [
            'criados' => $criados,
            'atualizados' => $atualizados,
            'ignorados' => count(array_filter($plan, static fn (array $item): bool => $item['acao'] === 'erro')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    private function linkParents(array $plan): void
    {
        $ids = InsightTopic::query()->pluck('id', 'slug');

        foreach ($plan as $item) {
            if ($item['acao'] === 'erro' || $item['tema_pai'] === '') {
                continue;
            }

            $parentSlug = Str::slug($item['tema_pai'], '_');
            $parentId = $ids[$parentSlug] ?? null;

            // Pai que não existe e ignorado, e não inventado: criar um tema a
            // partir de uma referência solta encheria a taxonomia de entradas
            // que ninguém pediu.
            if ($parentId === null || $parentId === ($ids[$item['identificador']] ?? null)) {
                continue;
            }

            InsightTopic::query()
                ->where('slug', $item['identificador'])
                ->update(['parent_id' => $parentId]);
        }
    }

    private function orNull(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }
}
