<?php

namespace App\Exceptions\Knowledge;

use RuntimeException;

/**
 * Falha operacional na camada de conhecimento.
 *
 * A mensagem e sempre um codigo operacional acompanhado de detalhe truncado.
 * Nunca carrega credencial nem conteudo integral de documento: o codigo e o que
 * vai para `knowledge_documents.error_message`, que fica visivel na tela.
 */
class KnowledgeProviderException extends RuntimeException
{
    public const NOT_CONFIGURED = 'provedor_nao_configurado';

    public const EMBEDDINGS_NOT_CONFIGURED = 'embeddings_nao_configurados';

    public const TIMEOUT = 'tempo_esgotado';

    public const RATE_LIMITED = 'limite_de_uso';

    public const UNAUTHORIZED = 'credencial_invalida';

    public const SERVICE_UNAVAILABLE = 'servico_indisponivel';

    public const BAD_REQUEST = 'pedido_invalido';

    public const INVALID_RESPONSE = 'resposta_invalida';

    public const DIMENSION_MISMATCH = 'dimensao_divergente';

    public const EXTRACTOR_UNAVAILABLE = 'extrator_indisponivel';

    public const PDF_EXTRACTOR_UNAVAILABLE = 'extrator_pdf_indisponivel';

    public const EMPTY_EXTRACTION = 'extracao_vazia';

    public const ANTIVIRUS_UNAVAILABLE = 'antivirus_indisponivel';

    public const INFECTED_FILE = 'arquivo_infectado';

    public const FILE_MISSING = 'arquivo_ausente';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $providerDetail = null,
    ) {
        parent::__construct($message);
    }

    public static function code(string $errorCode, ?string $detail = null): self
    {
        return new self($errorCode, $errorCode, $detail === null ? null : mb_substr($detail, 0, 500));
    }

    /**
     * Codigos em que uma nova tentativa faz sentido. Falta de configuracao,
     * extrator ausente e arquivo infectado nao melhoram com repeticao.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, [
            self::TIMEOUT,
            self::RATE_LIMITED,
            self::SERVICE_UNAVAILABLE,
        ], true);
    }
}
