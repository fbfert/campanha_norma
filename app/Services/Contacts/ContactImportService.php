<?php

namespace App\Services\Contacts;

use App\Enums\ConsentStatus;
use App\Enums\ContactImportRowStatus;
use App\Enums\ContactImportStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Models\ContactImport;
use App\Models\ContactImportRow;
use App\Models\Tag;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

class ContactImportService
{
    public const COLUMNS = [
        'nome' => 'name',
        'primeiro_nome' => 'first_name',
        'telefone' => 'phone',
        'email' => 'email',
        'cidade' => 'city',
        'estado' => 'state',
        'pais' => 'country',
        'origem' => 'source',
        'consentimento' => 'consent_status',
        'origem_consentimento' => 'consent_source',
        'data_consentimento' => 'consent_at',
        'observacoes' => 'notes',
        'etiquetas' => 'tags',
        'nao_contatar' => 'do_not_contact',
        'motivo_nao_contatar' => 'do_not_contact_reason',
    ];

    public function __construct(
        private readonly ContactDataService $contacts,
        private readonly PhoneNormalizerService $phones,
        private readonly ContactDuplicateService $duplicates,
        private readonly AuditLogger $audit,
    ) {}

    public function upload(UploadedFile $file): ContactImport
    {
        $stored = $file->storeAs('contact-imports', Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');

        $import = ContactImport::create([
            'user_id' => auth()->id(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $stored,
            'status' => ContactImportStatus::Uploaded,
            'options' => ['duplicate_strategy' => 'ignore'],
        ]);

        $this->readRows($import);

        return $import->refresh();
    }

    public function readRows(ContactImport $import): void
    {
        $path = Storage::disk('local')->path($import->stored_filename);
        $reader = str_ends_with(Str::lower($path), '.xlsx') ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $headers = [];
        $total = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                $values = array_map(fn ($value) => trim((string) $value), $row->toArray());
                if ($rowIndex === 1) {
                    $headers = array_map(fn ($value) => Str::slug($value, '_'), $values);

                    continue;
                }
                if (collect($values)->filter()->isEmpty()) {
                    continue;
                }
                $raw = [];
                foreach ($headers as $index => $header) {
                    $raw[$header] = $values[$index] ?? null;
                }
                ContactImportRow::updateOrCreate(
                    ['contact_import_id' => $import->id, 'row_number' => $rowIndex],
                    ['raw_data' => $raw, 'normalized_data' => null, 'status' => ContactImportRowStatus::Valid, 'error_messages' => null]
                );
                $total++;
            }
            break;
        }
        $reader->close();

        $import->update(['total_rows' => $total, 'status' => ContactImportStatus::Mapping]);
    }

    public function validateRows(ContactImport $import): ContactImport
    {
        $seen = [];
        $valid = $invalid = $duplicates = 0;

        foreach ($import->rows()->orderBy('row_number')->get() as $row) {
            [$normalized, $errors, $isDuplicate] = $this->normalizeRow($row->raw_data ?? [], $seen);
            $status = ContactImportRowStatus::Valid;
            if ($errors !== []) {
                $status = ContactImportRowStatus::Invalid;
                $invalid++;
            } elseif ($isDuplicate) {
                $status = ContactImportRowStatus::Duplicate;
                $duplicates++;
            } else {
                $valid++;
            }
            if (! empty($normalized['phone_normalized'])) {
                $seen[] = $normalized['phone_normalized'];
            }
            $row->update(['normalized_data' => $normalized, 'status' => $status, 'error_messages' => $errors]);
        }

        $import->update([
            'status' => ContactImportStatus::Ready,
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'duplicate_rows' => $duplicates,
            'error_rows' => $invalid,
        ]);

        return $import->refresh();
    }

    public function process(ContactImport $import, string $strategy = 'ignore'): ContactImport
    {
        if (in_array($import->status, [ContactImportStatus::Completed, ContactImportStatus::CompletedWithErrors], true)) {
            return $import;
        }

        $created = $updated = $ignored = 0;
        $import->update(['status' => ContactImportStatus::Processing, 'started_at' => now(), 'options' => ['duplicate_strategy' => $strategy]]);

        $import->rows()->whereIn('status', [ContactImportRowStatus::Valid->value, ContactImportRowStatus::Duplicate->value])->chunkById(100, function ($rows) use (&$created, &$updated, &$ignored, $strategy): void {
            DB::transaction(function () use ($rows, &$created, &$updated, &$ignored, $strategy): void {
                foreach ($rows as $row) {
                    $data = $row->normalized_data ?? [];
                    $existing = $this->duplicates->exactPhone($data['phone_normalized'] ?? null);
                    if ($existing && $strategy === 'interrupt') {
                        $row->update(['status' => ContactImportRowStatus::Ignored, 'error_messages' => ['Duplicado encontrado.']]);
                        $ignored++;

                        continue;
                    }
                    if ($existing && $strategy !== 'update') {
                        $row->update(['status' => ContactImportRowStatus::Ignored, 'contact_id' => $existing->id]);
                        $ignored++;

                        continue;
                    }
                    if ($existing) {
                        $clean = array_filter($data, fn ($value) => filled($value));
                        $clean['do_not_contact'] = $existing->do_not_contact || (bool) ($data['do_not_contact'] ?? false);
                        $this->contacts->update($existing, $clean, $this->tagIds($data['tags'] ?? ''));
                        $row->update(['status' => ContactImportRowStatus::Updated, 'contact_id' => $existing->id]);
                        $updated++;
                    } else {
                        $contact = $this->contacts->create($data, $this->tagIds($data['tags'] ?? ''));
                        $row->update(['status' => ContactImportRowStatus::Created, 'contact_id' => $contact->id]);
                        $created++;
                    }
                }
            });
        });

        $status = $import->invalid_rows > 0 ? ContactImportStatus::CompletedWithErrors : ContactImportStatus::Completed;
        $import->update(['status' => $status, 'created_rows' => $created, 'updated_rows' => $updated, 'ignored_rows' => $ignored, 'finished_at' => now()]);
        $this->audit->log('contacts.imported', 'Importacao de contatos processada.', $import, null, $import->only(['original_filename', 'status', 'created_rows', 'updated_rows', 'ignored_rows', 'invalid_rows']));

        return $import->refresh();
    }

    private function normalizeRow(array $raw, array $seen): array
    {
        $data = [
            'name' => trim((string) ($raw['nome'] ?? '')),
            'first_name' => trim((string) ($raw['primeiro_nome'] ?? '')),
            'phone' => trim((string) ($raw['telefone'] ?? '')),
            'email' => filled($raw['email'] ?? null) ? Str::lower(trim((string) $raw['email'])) : null,
            'city' => trim((string) ($raw['cidade'] ?? '')) ?: null,
            'state' => filled($raw['estado'] ?? null) ? Str::upper(trim((string) $raw['estado'])) : null,
            'country' => filled($raw['pais'] ?? null) ? Str::upper(trim((string) $raw['pais'])) : 'BR',
            'source' => ContactSource::tryFrom($raw['origem'] ?? '')?->value ?? ContactSource::Importacao->value,
            'consent_status' => ConsentStatus::tryFrom($raw['consentimento'] ?? '')?->value ?? ConsentStatus::NotInformed->value,
            'consent_source' => $raw['origem_consentimento'] ?? null,
            'consent_at' => $this->parseDate($raw['data_consentimento'] ?? null),
            'notes' => $raw['observacoes'] ?? null,
            'tags' => $raw['etiquetas'] ?? null,
            'do_not_contact' => in_array(Str::lower((string) ($raw['nao_contatar'] ?? '')), ['1', 'sim', 's', 'true'], true),
            'do_not_contact_reason' => $raw['motivo_nao_contatar'] ?? null,
            'status' => ContactStatus::Active->value,
        ];
        $errors = [];
        if ($data['name'] === '') {
            $errors[] = ['field' => 'nome', 'value' => '', 'problem' => 'Nome ausente.', 'suggestion' => 'Informe o nome completo.'];
        }
        if ($data['email'] && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['field' => 'email', 'value' => $data['email'], 'problem' => 'E-mail invalido.', 'suggestion' => 'Corrija ou deixe vazio.'];
        }
        $phone = $this->phones->normalize($data['phone']);
        if (! $phone->valid()) {
            $errors[] = ['field' => 'telefone', 'value' => $data['phone'], 'problem' => $phone->error, 'suggestion' => 'Informe DDI, DDD e numero.'];
        } else {
            $data['phone_normalized'] = $phone->normalized;
        }
        if (! empty($data['consent_at']) && $data['consent_at'] === 'invalid') {
            $errors[] = ['field' => 'data_consentimento', 'value' => $raw['data_consentimento'], 'problem' => 'Data invalida ou ambigua.', 'suggestion' => 'Use dd/mm/aaaa.'];
            $data['consent_at'] = null;
        }

        $duplicate = isset($data['phone_normalized']) && (in_array($data['phone_normalized'], $seen, true) || $this->duplicates->exactPhone($data['phone_normalized']) !== null);

        return [$data, $errors, $duplicate];
    }

    private function parseDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, (string) $value);
                if ($date && $date->format($format) === (string) $value) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                continue;
            }
        }

        return 'invalid';
    }

    private function tagIds(?string $tags): array
    {
        if (blank($tags)) {
            return [];
        }

        return collect(explode(';', $tags))->map(function ($name) {
            $name = trim($name);
            if ($name === '') {
                return null;
            }
            $tag = Tag::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'color' => '#176b4d', 'is_active' => true, 'created_by' => auth()->id()]);

            return $tag->id;
        })->filter()->all();
    }
}
