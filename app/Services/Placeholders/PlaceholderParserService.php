<?php

namespace App\Services\Placeholders;

class PlaceholderParserService
{
    public function __construct(private readonly PlaceholderCatalogService $catalog) {}

    public function parse(string $body): array
    {
        preg_match_all('/\{([^{}]*)\}/u', $body, $matches);

        $valid = [];
        $invalid = [];
        foreach ($matches[1] as $raw) {
            $name = trim((string) $raw);
            if ($name !== $raw || $name === '' || strtolower($name) !== $name || ! preg_match('/^[a-z_]+$/', $name)) {
                $invalid[] = '{'.$raw.'}';

                continue;
            }

            if ($this->catalog->exists($name)) {
                $valid[] = $name;
            } else {
                $invalid[] = $name;
            }
        }

        $malformed = [];
        if (preg_match_all('/\{[^}]*$/u', $body, $openMatches)) {
            $malformed = array_merge($malformed, $openMatches[0]);
        }
        if (str_contains($body, '{{') || str_contains($body, '}}')) {
            $malformed[] = '{{...}}';
        }

        return [
            'valid' => array_values(array_unique($valid)),
            'invalid' => array_values(array_unique($invalid)),
            'malformed' => array_values(array_unique($malformed)),
        ];
    }

    public function validate(string $body): array
    {
        $parsed = $this->parse($body);
        $errors = [];

        foreach ($parsed['invalid'] as $placeholder) {
            $errors[] = "Placeholder inválido: {$placeholder}.";
        }
        foreach ($parsed['malformed'] as $placeholder) {
            $errors[] = "Sintaxe incompleta ou invalida: {$placeholder}.";
        }

        return [$parsed, $errors];
    }
}
