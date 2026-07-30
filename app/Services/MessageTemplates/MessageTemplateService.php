<?php

namespace App\Services\MessageTemplates;

use App\Enums\MessageTemplateStatus;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Placeholders\PlaceholderParserService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageTemplateService
{
    public function __construct(
        private readonly PlaceholderParserService $parser,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data, User $user): MessageTemplate
    {
        $this->ensureValidBody($data['body']);
        $template = MessageTemplate::create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'body' => str_replace(["\r\n", "\r"], "\n", $data['body']),
            'status' => MessageTemplateStatus::from($data['status'] ?? 'active'),
            'version' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->version($template, $user);
        $this->audit->log('message_template.created', 'Modelo de mensagem criado.', $template, null, ['id' => $template->id, 'name' => $template->name], $user);

        return $template;
    }

    public function update(MessageTemplate $template, array $data, User $user): MessageTemplate
    {
        $this->ensureValidBody($data['body']);
        $old = $template->only(['name', 'description', 'body', 'status', 'version']);
        $bodyChanged = $template->body !== str_replace(["\r\n", "\r"], "\n", $data['body']);

        $template->fill([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'body' => str_replace(["\r\n", "\r"], "\n", $data['body']),
            'status' => MessageTemplateStatus::from($data['status'] ?? $template->status->value),
            'updated_by' => $user->id,
        ]);

        if ($bodyChanged) {
            $template->version++;
        }

        $template->save();

        if ($bodyChanged) {
            $this->version($template, $user);
            $this->audit->log('message_template.version_created', 'Versão de modelo preservada.', $template, null, ['version' => $template->version], $user);
        }

        $this->audit->log('message_template.updated', 'Modelo de mensagem atualizado.', $template, $old, $template->only(['name', 'description', 'status', 'version']), $user);

        return $template;
    }

    public function duplicate(MessageTemplate $template, User $user): MessageTemplate
    {
        $copy = $this->create([
            'name' => $template->name.' - copia',
            'description' => $template->description,
            'body' => $template->body,
            'status' => MessageTemplateStatus::Inactive->value,
        ], $user);

        $this->audit->log('message_template.duplicated', 'Modelo de mensagem duplicado.', $copy, null, ['source_id' => $template->id], $user);

        return $copy;
    }

    public function setStatus(MessageTemplate $template, MessageTemplateStatus $status, User $user): void
    {
        $old = ['status' => $template->status->value];
        $template->forceFill(['status' => $status, 'updated_by' => $user->id])->save();
        $this->audit->log($status === MessageTemplateStatus::Active ? 'message_template.activated' : 'message_template.inactivated', 'Status do modelo alterado.', $template, $old, ['status' => $status->value], $user);
    }

    public function delete(MessageTemplate $template, User $user): void
    {
        $template->delete();
        $this->audit->log('message_template.deleted', 'Modelo de mensagem excluído logicamente.', $template, null, ['id' => $template->id], $user);
    }

    public function restore(MessageTemplate $template, User $user): void
    {
        $template->restore();
        $this->audit->log('message_template.restored', 'Modelo de mensagem restaurado.', $template, null, ['id' => $template->id], $user);
    }

    public function ensureValidBody(string $body): void
    {
        [$parsed, $errors] = $this->parser->validate($body);

        if ($errors !== []) {
            throw ValidationException::withMessages(['body' => $errors]);
        }
    }

    private function version(MessageTemplate $template, User $user): MessageTemplateVersion
    {
        return MessageTemplateVersion::create([
            'message_template_id' => $template->id,
            'version' => $template->version,
            'name' => $template->name,
            'description' => $template->description,
            'body' => $template->body,
            'placeholders' => $this->parser->parse($template->body)['valid'],
            'created_by' => $user->id,
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'modelo';
        $slug = $base;
        $counter = 2;

        while (MessageTemplate::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
