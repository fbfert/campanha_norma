<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * Falha na comunicação com o provedor de IA.
 *
 * A mensagem e sempre operacional e nunca carrega corpo de mensagem do contato,
 * telefone ou credencial. O detalhe técnico do fornecedor fica em `providerDetail`,
 * já truncado, para diagnostico sem vazamento.
 */
class AiProviderException extends RuntimeException
{
    public const TIMEOUT = 'TIMEOUT';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const UNAUTHORIZED = 'UNAUTHORIZED';

    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    /** Pedido malformado ou recusado pelo fornecedor. Repetir não ajuda. */
    public const BAD_REQUEST = 'BAD_REQUEST';

    public const INVALID_RESPONSE = 'INVALID_RESPONSE';

    public const CIRCUIT_OPEN = 'CIRCUIT_OPEN';

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $providerDetail = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Códigos em que uma nova tentativa faz sentido.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, [
            self::TIMEOUT,
            self::RATE_LIMITED,
            self::SERVICE_UNAVAILABLE,
        ], true);
    }

    /**
     * Falhas que devem contar para o disjuntor. Resposta invalida e problema de
     * conteúdo, não de disponibilidade, e por isso não abre o circuito.
     */
    public function countsTowardsCircuit(): bool
    {
        return $this->errorCode !== self::INVALID_RESPONSE
            && $this->errorCode !== self::CIRCUIT_OPEN;
    }
}
