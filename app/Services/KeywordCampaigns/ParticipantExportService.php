<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\ReportExportStatus;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Analytics\ExportAnonymizer;
use App\Services\AuditLogger;
use App\Services\Reports\SpreadsheetValueSanitizer;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Exportação de participantes.
 *
 * Reaproveita da Etapa 9E o que já foi decidido e testado: o disco privado, a
 * expiração automática, a máscara de telefone e a neutralização de fórmula em
 * planilha. O que muda é a consulta.
 *
 * O código de cupom não aparece aqui em nenhuma hipótese. Cupom é valor, e uma
 * planilha circula por muito mais gente do que a tela que exige permissão para
 * mostrá-lo.
 */
class ParticipantExportService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
        private readonly ExportAnonymizer $anonymizer,
        private readonly SpreadsheetValueSanitizer $sanitizer,
    ) {}

    public function solicitar(User $user, KeywordCampaign $campaign, string $format = 'csv'): ReportExport
    {
        $format = $format === 'xlsx' ? 'xlsx' : 'csv';

        $export = ReportExport::query()->create([
            'user_id' => $user->id,
            'report_type' => 'keyword_campaigns.participants',
            'scope' => 'detailed',
            'purpose' => "Conferência da lista da campanha \"{$campaign->name}\".",
            'anonymized' => true,
            'format' => $format,
            'status' => ReportExportStatus::Pending,
            'filters' => ['keyword_campaign_id' => $campaign->id],
            'columns' => $this->colunas(),
            'total_rows' => 0,
            'expires_at' => now()->addHours((int) $this->settings->get('analytics.export_expiration_hours', 24)),
        ]);

        $this->audit->log(
            'keyword_campaign.participants_exported',
            'Exportação de participantes solicitada.',
            $export,
            null,
            ['keyword_campaign_id' => $campaign->id, 'formato' => $format],
            $user,
        );

        return $this->processar($export, $campaign);
    }

    public function processar(ReportExport $export, KeywordCampaign $campaign): ReportExport
    {
        try {
            $export->update(['status' => ReportExportStatus::Processing, 'started_at' => now()]);

            Storage::disk('local')->makeDirectory('report-exports');
            $caminho = 'report-exports/campanha-'.$campaign->id.'-participantes-'.$export->id.'.'.$export->format;
            $absoluto = Storage::disk('local')->path($caminho);

            $writer = $export->format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile($absoluto);
            $writer->addRow(Row::fromValues($this->sanitizer->row($this->colunas())));

            $total = 0;

            foreach ($this->linhas($campaign) as $linha) {
                $writer->addRow(Row::fromValues($this->sanitizer->row($linha)));
                $total++;
            }

            $writer->close();

            $export->update([
                'status' => ReportExportStatus::Completed,
                'file_path' => $caminho,
                'file_size' => file_exists($absoluto) ? filesize($absoluto) : null,
                'total_rows' => $total,
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            $export->update([
                'status' => ReportExportStatus::Failed,
                'finished_at' => now(),
                'error_code' => 'KEYWORD_PARTICIPANTS_EXPORT_FAILED',
                'error_message' => 'Falha ao gerar a exportação de participantes.',
            ]);
        }

        return $export->refresh();
    }

    /**
     * @return iterable<int, array<int, mixed>>
     */
    private function linhas(KeywordCampaign $campaign): iterable
    {
        $limite = (int) $this->settings->get('analytics.maximum_export_rows', 50000);

        return $campaign->participations()
            ->with('contact')
            ->orderBy('id')
            ->limit($limite)
            ->get()
            ->map(fn (KeywordCampaignParticipation $p): array => [
                $p->id,
                $p->displayName() ?? '',
                // Telefone mascarado: a conferência precisa reconhecer a pessoa,
                // e reconhecer não exige o número inteiro numa planilha.
                $this->anonymizer->maskPhone($p->contact?->phone_normalized),
                $p->matched_keyword,
                $p->status->label(),
                $p->eligibility->label(),
                $p->created_at?->format('d/m/Y H:i'),
                $p->invalidation_reason,
            ])
            ->all();
    }

    /**
     * Cabeçalho é identificador, e identificador não leva acento.
     *
     * @return array<int, string>
     */
    private function colunas(): array
    {
        return ['id', 'nome', 'telefone', 'palavra', 'situacao', 'elegibilidade', 'inscrito_em', 'motivo_invalidacao'];
    }
}
