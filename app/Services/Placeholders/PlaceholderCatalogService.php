<?php

namespace App\Services\Placeholders;

use App\Models\Contact;

class PlaceholderCatalogService
{
    public const NAME = 'nome';

    public const FIRST_NAME = 'primeiro_nome';

    public const PHONE = 'telefone';

    public const EMAIL = 'email';

    public const CITY = 'cidade';

    public const STATE = 'estado';

    public const COUNTRY = 'pais';

    public function all(): array
    {
        return [
            self::NAME => ['label' => 'Nome', 'field' => 'name'],
            self::FIRST_NAME => ['label' => 'Primeiro nome', 'field' => 'first_name'],
            self::PHONE => ['label' => 'Telefone', 'field' => 'phone'],
            self::EMAIL => ['label' => 'E-mail', 'field' => 'email'],
            self::CITY => ['label' => 'Cidade', 'field' => 'city'],
            self::STATE => ['label' => 'Estado', 'field' => 'state'],
            self::COUNTRY => ['label' => 'Pais', 'field' => 'country'],
        ];
    }

    public function names(): array
    {
        return array_keys($this->all());
    }

    public function exists(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    public function value(Contact $contact, string $name): ?string
    {
        $field = $this->all()[$name]['field'] ?? null;

        return $field ? $this->clean((string) ($contact->{$field} ?? '')) : null;
    }

    /**
     * O que entra no lugar do campo quando o contato não o tem.
     *
     * Recusar o envio continua sendo a regra: mandar "{cidade}" literal para um
     * cidadão é pior que não mandar, e para nome, telefone ou e-mail não existe
     * palavra genérica que sirva — "Olá você" não é saudação.
     *
     * A cidade é a exceção porque a frase funciona sem o nome dela. Quem se
     * inscreve por palavra-chave nasce sem cidade — a campanha só tem nome e
     * telefone —, e em 17/08/2026 isso deixou uma pessoa real sem receber a
     * pergunta da pesquisa: ela disse "Pode", o motor recusou o envio por campo
     * vazio, e a conversa foi para atendimento humano.
     *
     * "sua cidade", e não "cidade" seca: as perguntas cadastradas dizem
     * "melhorar {cidade}", e "melhorar cidade" não é português.
     *
     * @return array<string, string>
     */
    public function fallbacks(): array
    {
        return [
            self::CITY => 'sua cidade',
        ];
    }

    public function fallback(string $name): ?string
    {
        return $this->fallbacks()[$name] ?? null;
    }

    public function label(string $name): string
    {
        return $this->all()[$name]['label'] ?? $name;
    }

    private function clean(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[[:cntrl:]&&[^\r\n\t]]/u', '', $value) ?? '';
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';

        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }
}
