<?php

namespace App\Services\Contacts;

use App\Services\AuditLogger;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContactExportService
{
    public const HEADERS = ['nome', 'primeiro_nome', 'telefone', 'email', 'cidade', 'estado', 'pais', 'origem', 'status', 'consentimento', 'nao_contatar', 'etiquetas', 'ultimo_contato', 'criado_em'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function export(iterable $contacts, string $format = 'csv'): BinaryFileResponse
    {
        $format = $format === 'xlsx' ? 'xlsx' : 'csv';
        $path = storage_path('app/private/contact-export-'.now()->format('YmdHis').'.'.$format);
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADERS));
        $count = 0;

        foreach ($contacts as $contact) {
            $contact->loadMissing('tags');
            $writer->addRow(Row::fromValues([
                $contact->name,
                $contact->first_name,
                $contact->phone,
                $contact->email,
                $contact->city,
                $contact->state,
                $contact->country,
                $contact->source->value,
                $contact->status->value,
                $contact->consent_status->value,
                $contact->do_not_contact ? 'sim' : 'nao',
                $contact->tags->pluck('name')->join('; '),
                $contact->last_contacted_at?->format('d/m/Y H:i'),
                $contact->created_at?->format('d/m/Y H:i'),
            ]));
            $count++;
        }

        $writer->close();
        $this->audit->log('contacts.exported', 'Contatos exportados.', null, null, ['format' => $format, 'count' => $count]);

        return response()->download($path, 'contatos.'.$format)->deleteFileAfterSend(true);
    }

    public function template(): BinaryFileResponse
    {
        $path = storage_path('app/private/modelo-importacao-contatos.csv');
        file_put_contents($path, implode(',', ['nome', 'primeiro_nome', 'telefone', 'email', 'cidade', 'estado', 'pais', 'origem', 'consentimento', 'origem_consentimento', 'data_consentimento', 'observacoes', 'etiquetas', 'nao_contatar', 'motivo_nao_contatar']).PHP_EOL);

        return response()->download($path, 'modelo-importacao-contatos.csv')->deleteFileAfterSend(true);
    }
}
