<?php

namespace App\Enums;

/**
 * O que aconteceu com uma tentativa de inscrição.
 *
 * Quem chama o registro precisa saber mais do que "deu certo": o texto que a
 * pessoa recebe depende disso, e recusar em silêncio é o que produz a
 * reclamação de quem mandou a palavra e não teve resposta nenhuma.
 */
enum KeywordEnrollmentOutcome: string
{
    case Registrada = 'registrada';

    /**
     * Já estava inscrita. Não é erro: é a segunda mensagem de quem ficou na
     * dúvida se a primeira chegou.
     */
    case JaInscrita = 'ja_inscrita';

    /**
     * Registrada, mas o telefone casou com mais de um contato ativo. Não conta
     * como válida até um humano resolver.
     */
    case EmRevisao = 'em_revisao';

    case ForaDeVigencia = 'fora_de_vigencia';
    case LimiteAtingido = 'limite_atingido';
    case ListaCongelada = 'lista_congelada';
    case TelefoneInvalido = 'telefone_invalido';

    public function registrou(): bool
    {
        return $this === self::Registrada || $this === self::EmRevisao;
    }

    /**
     * A campanha atendeu esta mensagem?
     *
     * Vale também para quem já estava inscrita e para quem chegou fora da
     * vigência: nos três casos a campanha é quem fala com a pessoa, e a
     * abertura do atendimento de entrada não deve virar uma segunda mensagem
     * no mesmo minuto.
     */
    public function atendeuAMensagem(): bool
    {
        return $this->registrou() || $this === self::JaInscrita || $this === self::ForaDeVigencia;
    }

    public function label(): string
    {
        return match ($this) {
            self::Registrada => 'Inscrição registrada',
            self::JaInscrita => 'Já estava inscrita',
            self::EmRevisao => 'Registrada para revisão',
            self::ForaDeVigencia => 'Fora da vigência',
            self::LimiteAtingido => 'Limite de participantes atingido',
            self::ListaCongelada => 'Lista já congelada',
            self::TelefoneInvalido => 'Telefone inválido',
        };
    }
}
