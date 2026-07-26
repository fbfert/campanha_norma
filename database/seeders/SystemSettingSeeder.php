<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['group' => 'system', 'key' => 'system.name', 'value' => 'Gerenciador de Mensagens', 'type' => 'string', 'description' => 'Nome do sistema', 'is_public' => true],
            ['group' => 'system', 'key' => 'system.timezone', 'value' => 'America/Sao_Paulo', 'type' => 'string', 'description' => 'Fuso horario', 'is_public' => true],
            ['group' => 'system', 'key' => 'system.date_format', 'value' => 'd/m/Y', 'type' => 'string', 'description' => 'Formato de data', 'is_public' => true],
            ['group' => 'system', 'key' => 'system.datetime_format', 'value' => 'd/m/Y H:i', 'type' => 'string', 'description' => 'Formato de data e hora', 'is_public' => true],
            ['group' => 'system', 'key' => 'system.records_per_page', 'value' => '20', 'type' => 'integer', 'description' => 'Registros por pagina', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.default_country', 'value' => 'BR', 'type' => 'string', 'description' => 'Pais padrao dos contatos', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.default_country_code', 'value' => '55', 'type' => 'string', 'description' => 'DDI padrao dos contatos', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.require_phone', 'value' => '1', 'type' => 'boolean', 'description' => 'Exigir telefone no contato', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.prevent_duplicate_phone', 'value' => '1', 'type' => 'boolean', 'description' => 'Impedir telefone duplicado', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.default_records_per_page', 'value' => '20', 'type' => 'integer', 'description' => 'Registros por pagina no modulo de contatos', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.import_max_file_size', 'value' => '10', 'type' => 'integer', 'description' => 'Tamanho maximo da importacao em MB', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.import_max_rows', 'value' => '10000', 'type' => 'integer', 'description' => 'Quantidade maxima de linhas por importacao', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.allow_export', 'value' => '1', 'type' => 'boolean', 'description' => 'Permitir exportacao de contatos', 'is_public' => false],
            ['group' => 'contacts', 'key' => 'contacts.require_do_not_contact_reason', 'value' => '1', 'type' => 'boolean', 'description' => 'Exigir motivo para nao contatar', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.maximum_length', 'value' => '4096', 'type' => 'integer', 'description' => 'Tamanho maximo da mensagem em caracteres', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.preview_sample_size', 'value' => '5', 'type' => 'integer', 'description' => 'Quantidade de amostras na previa', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.allow_manual_message', 'value' => '1', 'type' => 'boolean', 'description' => 'Permitir mensagem avulsa em lotes', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.require_template_name', 'value' => '1', 'type' => 'boolean', 'description' => 'Exigir nome nos modelos de mensagens', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.block_unknown_placeholders', 'value' => '1', 'type' => 'boolean', 'description' => 'Bloquear placeholders desconhecidos', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.block_empty_placeholder_values', 'value' => '1', 'type' => 'boolean', 'description' => 'Bloquear contatos com valores vazios em placeholders', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.default_batch_status', 'value' => 'draft', 'type' => 'string', 'description' => 'Status padrao de novo lote', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.allow_random_sample', 'value' => '1', 'type' => 'boolean', 'description' => 'Permitir amostra aleatoria de contatos', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.allow_random_reorder', 'value' => '1', 'type' => 'boolean', 'description' => 'Permitir embaralhar ordem de lote', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.maximum_batch_size', 'value' => '1000', 'type' => 'integer', 'description' => 'Quantidade maxima de destinatarios por lote', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.processing_timeout_minutes', 'value' => '10', 'type' => 'integer', 'description' => 'Tempo maximo em processamento antes de revisao', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.dispatch_batch_size', 'value' => '1', 'type' => 'integer', 'description' => 'Quantidade de despachos por ciclo', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.dispatch_interval_seconds', 'value' => '30', 'type' => 'integer', 'description' => 'Intervalo tecnico entre ciclos de despacho', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.auto_pause_on_disconnect', 'value' => '1', 'type' => 'boolean', 'description' => 'Pausar processamento ao desconectar WhatsApp', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.unknown_result_review_required', 'value' => '1', 'type' => 'boolean', 'description' => 'Exigir revisao para resultado incerto', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.max_retry_delay_minutes', 'value' => '1440', 'type' => 'integer', 'description' => 'Atraso maximo entre tentativas em minutos', 'is_public' => false],
            ['group' => 'messages', 'key' => 'messages.polling_interval_seconds', 'value' => '5', 'type' => 'integer', 'description' => 'Intervalo de atualizacao da tela em segundos', 'is_public' => false],
            ['group' => 'reports', 'key' => 'reports.synchronous_export_max_rows', 'value' => '1000', 'type' => 'integer', 'description' => 'Maximo de linhas para exportacao sincrona', 'is_public' => false],
            ['group' => 'reports', 'key' => 'reports.maximum_export_rows', 'value' => '100000', 'type' => 'integer', 'description' => 'Maximo absoluto de linhas por exportacao', 'is_public' => false],
            ['group' => 'reports', 'key' => 'reports.export_expiration_hours', 'value' => '24', 'type' => 'integer', 'description' => 'Validade das exportacoes em horas', 'is_public' => false],
            ['group' => 'reports', 'key' => 'reports.allowed_formats', 'value' => 'csv,xlsx', 'type' => 'string', 'description' => 'Formatos permitidos para exportacao', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.audit_logs_days', 'value' => '365', 'type' => 'integer', 'description' => 'Retencao de auditoria em dias', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.technical_logs_days', 'value' => '90', 'type' => 'integer', 'description' => 'Retencao de logs tecnicos em dias', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.connection_events_days', 'value' => '180', 'type' => 'integer', 'description' => 'Retencao de eventos de conexao em dias', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.processing_events_days', 'value' => '365', 'type' => 'integer', 'description' => 'Retencao de eventos de processamento em dias', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.export_files_hours', 'value' => '24', 'type' => 'integer', 'description' => 'Retencao de arquivos exportados em horas', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.import_files_days', 'value' => '30', 'type' => 'integer', 'description' => 'Retencao de arquivos importados em dias', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.keep_message_history', 'value' => '1', 'type' => 'boolean', 'description' => 'Preservar historico de mensagens', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.keep_contact_snapshots', 'value' => '1', 'type' => 'boolean', 'description' => 'Preservar snapshots de contatos', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.worker_warning_minutes', 'value' => '5', 'type' => 'integer', 'description' => 'Alerta de worker sem heartbeat em minutos', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.worker_critical_minutes', 'value' => '15', 'type' => 'integer', 'description' => 'Critico de worker sem heartbeat em minutos', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.scheduler_warning_minutes', 'value' => '3', 'type' => 'integer', 'description' => 'Alerta de scheduler sem heartbeat em minutos', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.scheduler_critical_minutes', 'value' => '10', 'type' => 'integer', 'description' => 'Critico de scheduler sem heartbeat em minutos', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.disk_warning_percent', 'value' => '80', 'type' => 'integer', 'description' => 'Uso de disco para alerta em percentual', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.disk_critical_percent', 'value' => '90', 'type' => 'integer', 'description' => 'Uso de disco critico em percentual', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.stuck_message_minutes', 'value' => '10', 'type' => 'integer', 'description' => 'Tempo para mensagem presa em minutos', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.failed_jobs_warning', 'value' => '1', 'type' => 'integer', 'description' => 'Quantidade de jobs falhos para alerta', 'is_public' => false],
            ['group' => 'monitoring', 'key' => 'monitoring.failed_jobs_critical', 'value' => '10', 'type' => 'integer', 'description' => 'Quantidade de jobs falhos para critico', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.enabled', 'value' => '1', 'type' => 'boolean', 'description' => 'Habilitar caixa de entrada', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.stop_pending_messages_after_reply', 'value' => 'all_active_batches', 'type' => 'string', 'description' => 'Escopo de interrupcao apos resposta', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.default_assignment_mode', 'value' => 'manual', 'type' => 'string', 'description' => 'Modo padrao de atribuicao', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.default_status_after_incoming', 'value' => 'waiting_operator', 'type' => 'string', 'description' => 'Status apos mensagem recebida', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.default_status_after_outgoing', 'value' => 'waiting_contact', 'type' => 'string', 'description' => 'Status apos resposta manual', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.polling_interval_seconds', 'value' => '5', 'type' => 'integer', 'description' => 'Intervalo de atualizacao da caixa em segundos', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.maximum_manual_reply_length', 'value' => '4096', 'type' => 'integer', 'description' => 'Tamanho maximo da resposta manual', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.allow_unassigned_reply', 'value' => '0', 'type' => 'boolean', 'description' => 'Permitir resposta sem atribuicao', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.archive_resolved_after_days', 'value' => '30', 'type' => 'integer', 'description' => 'Arquivar resolvidas apos dias', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.mask_phone_by_default', 'value' => '1', 'type' => 'boolean', 'description' => 'Mascarar telefone por padrao', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.reopen_closed_conversation_on_incoming', 'value' => '1', 'type' => 'boolean', 'description' => 'Reabrir fechadas ao receber mensagem', 'is_public' => false],
            ['group' => 'inbox', 'key' => 'inbox.operator_scope', 'value' => 'assigned_and_unassigned', 'type' => 'string', 'description' => 'Escopo de visualizacao do operador', 'is_public' => false],
            ['group' => 'conversations', 'key' => 'conversations.sync_enabled', 'value' => '1', 'type' => 'boolean', 'description' => 'Habilitar sincronizacao de conversas WhatsApp', 'is_public' => false],
            ['group' => 'conversations', 'key' => 'conversations.sync_max_chats', 'value' => '100', 'type' => 'integer', 'description' => 'Maximo de chats por sincronizacao', 'is_public' => false],
            ['group' => 'conversations', 'key' => 'conversations.sync_messages_per_chat', 'value' => '50', 'type' => 'integer', 'description' => 'Maximo de mensagens por chat sincronizado', 'is_public' => false],
            ['group' => 'conversations', 'key' => 'conversations.sync_days_back', 'value' => '30', 'type' => 'integer', 'description' => 'Limite de dias para sincronizar mensagens', 'is_public' => false],
            ['group' => 'conversations', 'key' => 'conversations.sync_include_archived', 'value' => '0', 'type' => 'boolean', 'description' => 'Incluir chats arquivados na sincronizacao', 'is_public' => false],
            ['group' => 'conversations', 'key' => 'conversations.sync_interval_minutes', 'value' => '15', 'type' => 'integer', 'description' => 'Intervalo automatico de sincronizacao em minutos', 'is_public' => false],
            ['group' => 'conversations', 'key' => 'conversations.polling_interval_seconds', 'value' => '10', 'type' => 'integer', 'description' => 'Intervalo de atualizacao da tela de conversas', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.conversation_events_days', 'value' => '365', 'type' => 'integer', 'description' => 'Retencao de eventos de conversa', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.internal_notes_days', 'value' => '365', 'type' => 'integer', 'description' => 'Retencao de notas internas', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.incoming_metadata_days', 'value' => '180', 'type' => 'integer', 'description' => 'Retencao de metadados de entrada', 'is_public' => false],
            ['group' => 'retention', 'key' => 'retention.keep_conversation_messages', 'value' => '1', 'type' => 'boolean', 'description' => 'Preservar mensagens de conversa', 'is_public' => false],
        ];

        foreach ($settings as $setting) {
            SystemSetting::query()->updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
