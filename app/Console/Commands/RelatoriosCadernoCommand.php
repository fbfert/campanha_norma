<?php

namespace App\Console\Commands;

use App\Enums\InsightUrgency;
use App\Models\ConversationFlow;
use App\Models\ConversationInsight;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * O caderno de resposta em HTML, antes de existir tela.
 *
 * A subetapa 9E entregou leitura agregada e parou ali. Este comando produz o
 * caminho de volta: uma página por pessoa, com o que ela escreveu e o que a
 * interpretação extraiu, para alguém responder à mão.
 *
 * Ele existe para ser mostrado antes de virar tela. Metade do que a 9F desenha
 * tende a ser cortada depois que alguém lê dez páginas de verdade, e cortar
 * antes de construir é mais barato que cortar depois.
 *
 * Nada aqui envia, agenda ou grava. É leitura sobre o que já está no banco.
 */
class RelatoriosCadernoCommand extends Command
{
    protected $signature = 'relatorios:caderno'
        .' {--de= : Início do período (AAAA-MM-DD)}'
        .' {--ate= : Fim do período (AAAA-MM-DD)}' // ortografia:ignorar - "ate" nomeia a opção, e identificador não leva acento
        .' {--fluxo= : Restringe a um fluxo conversacional}'
        .' {--por= : Nome de quem está gerando o caderno}'
        .' {--saida=storage/app/private/caderno.html : Arquivo de saída}'; // ortografia:ignorar - "saida" nomeia a opção e o caminho

    protected $description = 'Gera o caderno de resposta em HTML, uma página por pessoa.';

    public function handle(SystemSettingService $settings): int
    {
        $de = Carbon::parse($this->option('de') ?: now()->subDays(30)->toDateString())->startOfDay();
        $ate = Carbon::parse($this->option('ate') ?: now()->toDateString())->endOfDay();

        if ($de->gt($ate)) {
            $this->error('O início do período é posterior ao fim.');

            return self::FAILURE;
        }

        $fluxoId = $this->option('fluxo') === null ? null : (int) $this->option('fluxo');
        $fluxo = $fluxoId === null ? null : ConversationFlow::find($fluxoId);

        if ($fluxoId !== null && $fluxo === null) {
            $this->error("Não existe fluxo #{$fluxoId}.");

            return self::FAILURE;
        }

        $limiar = (float) $settings->get('analytics.low_confidence_threshold', 0.70);
        $insights = $this->ordenados($this->insights($de, $ate, $fluxoId));

        if ($insights->isEmpty()) {
            $this->warn('Nenhum insight no período. O caderno sairia com capa e nenhuma página.');

            return self::SUCCESS;
        }

        $porQuem = (string) ($this->option('por') ?: get_current_user());
        $html = $this->html($insights, $de, $ate, $fluxo, $porQuem, $limiar);

        $destino = $this->caminho((string) $this->option('saida'));
        $this->prepararDiretorio($destino);
        file_put_contents($destino, $html);

        $this->info("{$insights->count()} página(s) escritas em {$destino}.");
        $this->line('Documento nominal: leia antes de compartilhar, e não encaminhe.');

        return self::SUCCESS;
    }

    /** @return Collection<int, ConversationInsight> */
    private function insights(Carbon $de, Carbon $ate, ?int $fluxoId): Collection
    {
        return ConversationInsight::query()
            ->whereBetween('conversation_insights.created_at', [$de, $ate])
            ->when($fluxoId, fn ($query) => $query->where('conversation_flow_id', $fluxoId))
            ->with(['contact', 'sourceMessage', 'topic'])
            ->get();
    }

    /**
     * Urgência alta primeiro; empatada, a resposta mais longa primeiro.
     *
     * A ordenação acontece em memória, e não no banco, porque o critério de
     * tamanho é o comprimento do texto que a pessoa escreveu: `LENGTH` conta
     * bytes no MySQL, e uma resposta acentuada pareceria maior que uma sem
     * acento do mesmo tamanho. Com duzentas pessoas, ordenar em memória não
     * custa nada e conta caracteres de verdade.
     *
     * @param  Collection<int, ConversationInsight>  $insights
     * @return Collection<int, ConversationInsight>
     */
    private function ordenados(Collection $insights): Collection
    {
        return $insights
            ->sort(fn (ConversationInsight $a, ConversationInsight $b): int => [
                $this->pesoDaUrgencia($b->urgency),
                mb_strlen($this->frase($b)),
            ] <=> [
                $this->pesoDaUrgencia($a->urgency),
                mb_strlen($this->frase($a)),
            ])
            ->values();
    }

    private function pesoDaUrgencia(?InsightUrgency $urgencia): int
    {
        return match ($urgencia) {
            InsightUrgency::High => 3,
            InsightUrgency::Medium => 2,
            InsightUrgency::Low => 1,
            default => 0,
        };
    }

    /**
     * A frase literal, sem paráfrase e sem corte.
     *
     * O caderno reserva uma página inteira por pessoa justamente para o texto
     * caber por completo. Resumir aqui trocaria o que o eleitor escreveu pelo
     * que o sistema achou que ele quis dizer, que é o contrário do documento.
     */
    private function frase(ConversationInsight $insight): string
    {
        return trim((string) ($insight->sourceMessage?->body ?? ''));
    }

    /** @param  Collection<int, ConversationInsight>  $insights */
    private function html(
        Collection $insights,
        Carbon $de,
        Carbon $ate,
        ?ConversationFlow $fluxo,
        string $porQuem,
        float $limiar,
    ): string {
        $periodo = $de->format('d/m/Y').' a '.$ate->format('d/m/Y');
        $nomeDoFluxo = $fluxo?->name ?? 'todos os fluxos';
        $geradoEm = now()->format('d/m/Y H:i');

        $paginas = $insights
            ->map(fn (ConversationInsight $insight): string => $this->pagina($insight, $limiar))
            ->implode("\n");

        /*
         | O estilo vai embutido no próprio arquivo, e isso é deliberado.
         |
         | A regra do projeto proíbe `<style>` dentro de view porque view é
         | parte do sistema e a cor precisa sair de um token declarado uma vez.
         | Este arquivo não é view: é artefato de saída, que sai do servidor,
         | vai por anexo ou pendrive e abre num navegador que não conhece a
         | folha de estilo do sistema. Um caderno que depende de CSS externo
         | chega sem formatação nenhuma na mão de quem vai lê-lo.
         */
        return <<<HTML
        <!doctype html>
        <html lang="pt-BR">
        <head>
        <meta charset="utf-8">
        <title>Caderno de resposta — {$this->escapar($periodo)}</title>
        <style>
        {$this->estilo()}
        </style>
        </head>
        <body>

        <section class="capa">
        <h1>Caderno de resposta</h1>
        <dl>
        <dt>Período</dt><dd>{$this->escapar($periodo)}</dd>
        <dt>Fluxo</dt><dd>{$this->escapar($nomeDoFluxo)}</dd>
        <dt>Pessoas</dt><dd>{$insights->count()}</dd>
        <dt>Gerado em</dt><dd>{$this->escapar($geradoEm)}</dd>
        <dt>Gerado por</dt><dd>{$this->escapar($porQuem)}</dd>
        </dl>
        <p class="alerta"><strong>Documento nominal.</strong> Traz nome, cidade e o que cada pessoa
        escreveu. Não encaminhe, não publique e não compartilhe fora de quem precisa responder.</p>
        <p>Este material é escuta de demanda. <strong>Não é pesquisa eleitoral registrada</strong> e não
        pergunta intenção de voto.</p>
        </section>

        {$paginas}

        <p class="rodape">Escuta de demanda — não é pesquisa eleitoral registrada. Documento nominal,
        gerado por {$this->escapar($porQuem)} em {$this->escapar($geradoEm)}.</p>

        </body>
        </html>
        HTML;
    }

    private function pagina(ConversationInsight $insight, float $limiar): string
    {
        $nome = $insight->contact?->first_name ?: $insight->contact?->name ?: 'Sem nome cadastrado';
        $cidade = trim(($insight->contact?->city ?? '').' '.($insight->contact?->state ?? '')) ?: 'cidade não cadastrada';
        $tema = $insight->topic?->name ?? 'sem tema atribuído';
        $urgencia = $insight->urgency?->label() ?? 'não informada';
        $frase = $this->frase($insight) ?: 'A mensagem de origem não tem texto.';

        $declarada = $insight->locality_text
            ? '<p class="lugar">Localidade que a própria pessoa declarou: <strong>'.$this->escapar($insight->locality_text).'</strong></p>'
            : '';

        $confianca = $insight->confidence;
        $aviso = ($confianca !== null && $confianca < $limiar)
            ? '<p class="alerta"><strong>Confiança baixa ('.number_format($confianca, 2, ',', '.').').</strong> '
                .'A leitura automática pode ter errado. Confira a mensagem original antes de responder.</p>'
            : '';

        return <<<HTML
        <section class="pessoa">
        <h2>{$this->escapar($nome)}</h2>
        <p class="lugar">{$this->escapar($cidade)} — tema: {$this->escapar($tema)} — urgência: {$this->escapar($urgencia)}</p>
        {$declarada}
        {$aviso}

        <h3>O que ela escreveu</h3>
        <blockquote>{$this->escapar($frase)}</blockquote>

        <h3>O que ela levantou</h3>
        <dl class="campos">
        <dt>Problema identificado</dt><dd>{$this->escapar($insight->identified_problem ?: '—')}</dd>
        <dt>Ação sugerida</dt><dd>{$this->escapar($insight->suggested_action ?: '—')}</dd>
        <dt>Resultado desejado</dt><dd>{$this->escapar($insight->desired_result ?: '—')}</dd>
        </dl>

        <h3>Linha vermelha — o que não prometer</h3>
        <p class="vazio">Ainda não escrita para o tema "{$this->escapar($tema)}". Preencher isto por tema
        é trabalho humano, e é o que impede uma promessa dita no áudio.</p>
        </section>
        HTML;
    }

    /**
     * O estilo do caderno, embutido no arquivo gerado.
     *
     * Ele mora fora deste código porque o caderno abre num navegador que não
     * conhece a folha de estilo do sistema, e porque CSS escrito dentro de um
     * heredoc de PHP não é lido por ninguém que vá ajustá-lo.
     */
    private function estilo(): string
    {
        return trim((string) file_get_contents(resource_path('caderno/caderno.css')));
    }

    private function escapar(?string $texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function caminho(string $saida): string
    {
        return str_starts_with($saida, '/') ? $saida : base_path($saida);
    }

    private function prepararDiretorio(string $destino): void
    {
        $diretorio = dirname($destino);

        if (! is_dir($diretorio)) {
            mkdir($diretorio, 0755, true);
        }
    }
}
