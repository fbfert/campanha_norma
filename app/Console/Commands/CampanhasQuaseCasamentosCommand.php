<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageDirection;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Services\KeywordCampaigns\KeywordMatcherService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * O insumo para decidir, com número real, se vale relaxar o casamento.
 *
 * O v1 não tolera erro de digitação, e isso é escolha: distância de edição
 * aproxima palavra errada de palavra certa, mas também aproxima duas palavras
 * legítimas e diferentes, e calibrar o limiar sem dado é chute.
 *
 * Este comando não registra nada e não inscreve ninguém. Ele só mostra quantas
 * pessoas chegaram a uma letra de distância — que é o número que falta para a
 * decisão deixar de ser chute.
 */
class CampanhasQuaseCasamentosCommand extends Command
{
    protected $signature = 'campanhas:quase-casamentos
        {--campanha= : Analisa apenas esta campanha}
        {--from= : Data inicial das mensagens}
        {--to= : Data final das mensagens}
        {--limite=30 : Quantas variações mostrar}';

    protected $description = 'Lista as mensagens que quase casaram com uma palavra-chave, sem registrar nada.';

    public function handle(KeywordMatcherService $matcher): int
    {
        $campanhas = KeywordCampaign::query()
            ->when($this->option('campanha'), fn ($query) => $query->whereKey((int) $this->option('campanha')))
            ->orderBy('id')
            ->get();

        if ($campanhas->isEmpty()) {
            $this->warn('Nenhuma campanha encontrada.');

            return self::SUCCESS;
        }

        foreach ($campanhas as $campanha) {
            $desde = $this->option('from')
                ? Carbon::parse((string) $this->option('from'))->startOfDay()
                : $campanha->starts_at;

            $ate = $this->option('to')
                ? Carbon::parse((string) $this->option('to'))->endOfDay()
                : ($campanha->ends_at?->isPast() ? $campanha->ends_at : now());

            $palavras = $campanha->keywordList();

            if ($palavras === []) {
                continue;
            }

            /** @var array<string, array{keyword: string, total: int}> $contagem */
            $contagem = [];
            $mensagensAnalisadas = 0;

            ConversationMessage::query()
                ->where('direction', ConversationMessageDirection::Incoming)
                ->whereNotNull('body')
                ->when($desde, fn ($query) => $query->where('received_at', '>=', $desde))
                ->when($ate, fn ($query) => $query->where('received_at', '<=', $ate))
                ->orderBy('id')
                ->chunkById(500, function ($mensagens) use ($palavras, $matcher, &$contagem, &$mensagensAnalisadas): void {
                    foreach ($mensagens as $mensagem) {
                        $mensagensAnalisadas++;
                        $texto = $matcher->textoParaCasamento($mensagem);

                        // Quem casou de verdade não é quase-casamento.
                        if ($matcher->match($texto, $palavras) !== null) {
                            continue;
                        }

                        foreach ($matcher->quaseCasamentos($texto, $palavras) as $achado) {
                            $chave = $achado['word'].' → '.$achado['keyword'];
                            $contagem[$chave] ??= ['keyword' => $achado['keyword'], 'total' => 0];
                            $contagem[$chave]['total']++;
                        }
                    }
                });

            $this->line("Campanha #{$campanha->id} — {$campanha->name}");
            $this->line("  {$mensagensAnalisadas} mensagens analisadas no período.");

            if ($contagem === []) {
                $this->info('  nenhum quase-casamento: o casamento estrito não está deixando ninguém de fora.');
                $this->newLine();

                continue;
            }

            uasort($contagem, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

            $linhas = [];
            foreach (array_slice($contagem, 0, (int) $this->option('limite'), true) as $variacao => $dados) {
                $linhas[] = [$variacao, $dados['total']];
            }

            $this->table(['Variação', 'Vezes'], $linhas);

            $perdidas = array_sum(array_column($contagem, 'total'));
            $this->comment("  {$perdidas} ".($perdidas === 1 ? 'mensagem ficou' : 'mensagens ficaram')
                .' a uma letra de distância. Nenhuma foi inscrita.');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
