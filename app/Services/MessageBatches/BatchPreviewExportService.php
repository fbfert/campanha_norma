<?php

namespace App\Services\MessageBatches;

use App\Models\MessageBatch;
use App\Services\AuditLogger;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BatchPreviewExportService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function export(MessageBatch $batch, string $format = 'csv'): BinaryFileResponse
    {
        $format = $format === 'xlsx' ? 'xlsx' : 'csv';
        $path = storage_path('app/private/lote-previa-'.$batch->id.'-'.now()->format('YmdHis').'.'.$format);
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['posicao', 'nome', 'telefone', 'cidade', 'status_de_aptidao', 'motivo', 'mensagem_renderizada']));

        foreach ($batch->recipients()->orderBy('random_position')->cursor() as $recipient) {
            $writer->addRow(Row::fromValues([
                $recipient->random_position,
                $recipient->contact_name_snapshot,
                $recipient->contact_phone_snapshot,
                $recipient->contact_city_snapshot,
                $recipient->eligibility_status->value,
                $recipient->ineligibility_reason,
                $recipient->rendered_message,
            ]));
        }

        $writer->close();
        $this->audit->log('message_batch.preview_exported', 'Previa do lote exportada.', $batch, null, ['format' => $format]);

        return response()->download($path, 'lote-previa-'.$batch->id.'.'.$format)->deleteFileAfterSend(true);
    }
}
