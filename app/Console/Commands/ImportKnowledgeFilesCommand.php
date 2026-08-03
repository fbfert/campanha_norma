<?php

namespace App\Console\Commands;

use App\Enums\KnowledgeDocumentType;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\Knowledge\DocumentIngestionService;
use App\Services\Knowledge\KnowledgeIndexingService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Importa fichas de conhecimento versionadas no repositório.
 *
 * As fichas nasceram digitadas direto no banco de produção. O conteúdo ficava
 * só lá: não dava para revisar numa alteração, não dava para reconstruir o
 * ambiente e não havia como saber o que tinha mudado entre uma versão e outra
 * do que a IA usa para responder um eleitor.
 *
 * O texto passa a morar em `resources/conhecimento`, e este comando o leva para
 * a base pelo mesmo caminho da tela — antivírus, verificação de duplicidade,
 * fatiamento e indexação. Ficha já importada é reconhecida pelo conteúdo e
 * pulada, então rodar de novo não duplica nada.
 */
class ImportKnowledgeFilesCommand extends Command
{
    protected $signature = 'knowledge:import-files
        {--base= : Identificador da base de destino}
        {--dir=resources/conhecimento/norma : Diretório das fichas}
        {--apply : Executa de verdade; sem isto apenas simula}';

    protected $description = 'Importa para a base as fichas de conhecimento versionadas no repositório.';

    /**
     * Título e tipo de cada ficha.
     *
     * O tipo não é decoração: a tela separa por ele, e a governança distingue
     * biografia de posição declarada. Ficha sem entrada aqui entra como
     * biografia, que é o tipo mais restrito dos que produzem texto.
     *
     * @var array<string, array{0: string, 1: KnowledgeDocumentType}>
     */
    private const FICHAS = [
        'quem-e-a-professora-norma' => ['Quem é a Professora Norma', KnowledgeDocumentType::Biography],
        'trajetoria-na-educacao' => ['Trajetória na educação', KnowledgeDocumentType::Biography],
        'como-nasceu-a-rainbow' => ['Como nasceu a Rainbow', KnowledgeDocumentType::PublicHistory],
        'ensino-superior-nas-cidades-pequenas' => ['Ensino superior nas cidades pequenas', KnowledgeDocumentType::InstitutionalCompetence],
        'como-a-norma-pensa-a-educacao' => ['Como a Norma pensa a educação', KnowledgeDocumentType::OfficialPosition],
        'tecnologia-e-inteligencia-artificial' => ['Tecnologia e inteligência artificial', KnowledgeDocumentType::OfficialPosition],
        'mulher-lideranca-e-empreendedorismo' => ['Mulher, liderança e empreendedorismo', KnowledgeDocumentType::OfficialPosition],
        'por-que-a-candidatura' => ['Por que a candidatura', KnowledgeDocumentType::OfficialPosition],
    ];

    public function handle(DocumentIngestionService $ingestion, KnowledgeIndexingService $indexing): int
    {
        $base = $this->resolveBase();

        if (! $base) {
            $this->error('Base de conhecimento não encontrada. Informe --base com o identificador.');

            return self::FAILURE;
        }

        $diretorio = base_path((string) $this->option('dir'));
        $arquivos = glob($diretorio.'/*.md') ?: [];

        if ($arquivos === []) {
            $this->error("Nenhuma ficha encontrada em {$diretorio}.");

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('apply');
        $usuario = User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'administrador'))->first();

        if ($aplicar && ! $usuario) {
            $this->error('Nenhum administrador cadastrado para responder pela aprovação.');

            return self::FAILURE;
        }

        $this->info(count($arquivos).' ficha(s) em '.$diretorio.' -> base "'.$base->name.'".');
        $this->line('');

        $importadas = 0;
        $puladas = 0;

        foreach ($arquivos as $caminho) {
            $slug = basename($caminho, '.md');
            [$titulo, $tipo] = self::FICHAS[$slug] ?? [$slug, KnowledgeDocumentType::Biography];

            if (! $aplicar) {
                $this->line(sprintf('  %-44s %s', $titulo, $tipo->value));

                continue;
            }

            try {
                $documento = $ingestion->store($base, new UploadedFile($caminho, $slug.'.md', 'text/markdown', null, true), [
                    'title' => $titulo,
                    'type' => $tipo->value,
                    'source' => 'Ficha versionada no repositório',
                ], $usuario);

                $documento = $indexing->approve($indexing->index($documento), $usuario);

                $this->line(sprintf('  %-44s %d trecho(s)', $titulo, (int) $documento->fresh()->chunk_count));
                $importadas++;
            } catch (Throwable $erro) {
                // Ficha já presente cai aqui pela verificação de duplicidade, e
                // e o caso comum de uma segunda execução: não e falha.
                $this->line(sprintf('  %-44s pulada (%s)', $titulo, mb_substr($erro->getMessage(), 0, 60)));
                $puladas++;
            }
        }

        $this->line('');

        if (! $aplicar) {
            $this->info('Simulação. Use --apply para executar.');

            return self::SUCCESS;
        }

        $this->info("{$importadas} importada(s) e {$puladas} pulada(s).");

        return self::SUCCESS;
    }

    private function resolveBase(): ?KnowledgeBase
    {
        $informado = $this->option('base');

        if ($informado) {
            return KnowledgeBase::query()
                ->where('id', $informado)
                ->orWhere('slug', $informado)
                ->first();
        }

        return KnowledgeBase::query()->orderBy('id')->first();
    }
}
