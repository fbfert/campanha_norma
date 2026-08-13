<?php

namespace App\Enums;

/**
 * O que a primeira mensagem automática faz com o texto que a pessoa escreveu.
 *
 * Quem chega por lote recebe a apresentação antes de dizer qualquer coisa.
 * Aqui é o contrário: a pessoa já escreveu, e já escreveu alguma coisa
 * específica. Abrir com a apresentação da pesquisa por cima de uma pergunta é
 * responder outra coisa, e quem faz isso ao telefone é atendimento ruim.
 */
enum InboundOpeningMode: string
{
    /**
     * Responde o que a pessoa escreveu e, na mesma mensagem, apresenta a
     * pesquisa. Se a IA não produzir texto confiável, nada sai e a conversa
     * fica na fila — puxar a pesquisa sem responder seria ignorar a pessoa.
     */
    case AiThenSurvey = 'ai_then_survey';

    /**
     * Só a apresentação, sem tentar responder. Serve para perfil cujo assunto
     * não comporta resposta gerada — recado de fora do horário, aviso de
     * campanha encerrada.
     */
    case SurveyOnly = 'survey_only';

    public function label(): string
    {
        return match ($this) {
            self::AiThenSurvey => 'Responder a mensagem e apresentar a pesquisa',
            self::SurveyOnly => 'Apenas apresentar a pesquisa',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AiThenSurvey => 'A IA lê o que a pessoa escreveu e responde; a apresentação vai na mesma mensagem. Sem resposta confiável, a conversa fica na fila para você.',
            self::SurveyOnly => 'Envia apenas o texto de apresentação do perfil, sem tentar responder ao que a pessoa escreveu.',
        };
    }
}
