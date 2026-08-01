<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeBaseStatus;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importação da ficha das bases de conhecimento.
 *
 * Importa **a ficha**, nunca documento: nome, descrição, propósito e política
 * de uso. Documento oficial entra pela tela da base, passa por antivírus,
 * extração e indexação, e so vale depois de alguém aprovar. Nada disso pode ser
 * pulado por uma planilha.
 *
 * Pelo mesmo motivo, quatro colunas da exportação são ignoradas:
 *
 * - `situacao` — base nova nasce em rascunho e base existente mantem a situação
 *   que ja tem. Ativar e o ato que torna a base alcancável pela busca, e ele
 *   tem tela própria e registro em auditoria.
 * - `versao`, `documentos`, `documentos_aprovados`, `fluxos` — derivados.
 * - `aprovada_por` e `aprovada_em` — aprovação e ato de uma pessoa neste
 *   sistema. Escrever isso por arquivo seria forjar o registro.
 * - `provedor` — e configuração da instalação, não da base.
 */
class KnowledgeBaseImporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{linha: int, dados: array<string, string>}>  $rows
     * @return list<array<string, mixed>>
     */
    public function plan(array $rows): array
    {
        $existing = KnowledgeBase::query()->pluck('id', 'slug');
        $slugsNoArquivo = [];
        $plan = [];

        foreach ($rows as $row) {
            $data = $row['dados'];
            $name = trim($data['base'] ?? $data['nome'] ?? '');
            $slug = Str::slug(trim($data['identificador'] ?? '') ?: $name);

            $error = match (true) {
                $name === '' => 'Sem o nome da base.',
                $slug === '' => 'Sem identificador e sem nome de onde derivá-lo.',
                in_array($slug, $slugsNoArquivo, true) => 'Identificador repetido dentro do próprio arquivo.',
                default => null,
            };

            $slugsNoArquivo[] = $slug;

            $plan[] = [
                'linha' => $row['linha'],
                'acao' => $error !== null ? 'erro' : ($existing->has($slug) ? 'atualizar' : 'criar'),
                'motivo' => $error,
                'base' => $name,
                'identificador' => $slug,
                'atributos' => [
                    'name' => $name,
                    'description' => $this->orNull($data['descricao'] ?? ''),
                    'purpose' => $this->orNull($data['proposito'] ?? ''),
                    'usage_policy' => $this->orNull($data['politica_de_uso'] ?? ''),
                ],
            ];
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @return array{criadas: int, atualizadas: int, ignoradas: int}
     */
    public function apply(array $plan, User $user): array
    {
        $criadas = $atualizadas = 0;

        DB::transaction(function () use ($plan, $user, &$criadas, &$atualizadas): void {
            foreach ($plan as $item) {
                if ($item['acao'] === 'erro') {
                    continue;
                }

                $base = KnowledgeBase::query()->where('slug', $item['identificador'])->first();

                if ($base) {
                    $before = $base->only(array_keys($item['atributos']));
                    $base->update($item['atributos'] + ['updated_by' => $user->id]);
                    $this->audit->log('knowledge_base.imported_update', 'Ficha de base atualizada por importação.', $base, $before, $item['atributos'], $user);
                    $atualizadas++;

                    continue;
                }

                $base = KnowledgeBase::create($item['atributos'] + [
                    'slug' => $item['identificador'],
                    // Rascunho, sempre. Ver a nota da classe.
                    'status' => KnowledgeBaseStatus::Draft,
                    'version' => 1,
                    'provider' => (string) config('knowledge.provider'),
                    'created_by' => $user->id,
                ]);
                $this->audit->log('knowledge_base.imported_create', 'Base criada por importação.', $base, null, $item['atributos'], $user);
                $criadas++;
            }
        });

        return [
            'criadas' => $criadas,
            'atualizadas' => $atualizadas,
            'ignoradas' => count(array_filter($plan, static fn (array $item): bool => $item['acao'] === 'erro')),
        ];
    }

    private function orNull(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }
}
