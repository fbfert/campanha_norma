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
