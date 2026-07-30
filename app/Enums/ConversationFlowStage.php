<?php

namespace App\Enums;

enum ConversationFlowStage: string
{
    case Inactive = 'inactive';
    case InitialMessageSent = 'initial_message_sent';
    case WaitingPermission = 'waiting_permission';
    case PermissionGranted = 'permission_granted';
    case PermissionDenied = 'permission_denied';
    case QuestionSelected = 'question_selected';
    case WaitingAnswer = 'waiting_answer';
    case AnswerReceived = 'answer_received';
    case Completed = 'completed';
    case OptedOut = 'opted_out';
    case Paused = 'paused';
    case WaitingHuman = 'waiting_human';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Inativo',
            self::InitialMessageSent => 'Mensagem inicial enviada',
            self::WaitingPermission => 'Aguardando permissão',
            self::PermissionGranted => 'Permissão concedida',
            self::PermissionDenied => 'Permissão negada',
            self::QuestionSelected => 'Pergunta selecionada',
            self::WaitingAnswer => 'Aguardando resposta',
            self::AnswerReceived => 'Resposta recebida',
            self::Completed => 'Concluído',
            self::OptedOut => 'Opt-out',
            self::Paused => 'Pausado',
            self::WaitingHuman => 'Aguardando humano',
            self::Failed => 'Falhou',
        };
    }

    /**
     * Estagios terminais não podem voltar para trás por mensagem fora de ordem.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::OptedOut, self::PermissionDenied, self::Failed], true);
    }
}
