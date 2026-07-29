<?php

namespace App\Services\Ai;

/**
 * Validacao server-side da saida do modelo.
 *
 * Roda sempre, mesmo quando o provedor declara suportar saida estruturada: a
 * conformidade nunca e presumida a partir da promessa do fornecedor.
 *
 * Cobre o subconjunto de JSON Schema efetivamente usado pelo registro: type,
 * enum, required, additionalProperties, minimum, maximum, maxLength, maxItems e
 * items.
 */
class AiResponseValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array{valid: bool, data: ?array<string, mixed>, errors: array<int, string>}
     */
    public function validate(string $rawContent, array $schema): array
    {
        $decoded = json_decode($this->unwrap($rawContent), true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return $this->failure(['resposta_nao_e_json_valido']);
        }

        $errors = $this->check($decoded, $schema, '');

        if ($errors !== []) {
            return $this->failure($errors);
        }

        return ['valid' => true, 'data' => $decoded, 'errors' => []];
    }

    /**
     * Alguns provedores devolvem o JSON dentro de uma cerca de codigo mesmo com
     * saida estruturada solicitada. Removemos a cerca antes de decodificar, sem
     * tentar consertar JSON malformado.
     */
    private function unwrap(string $content): string
    {
        $content = trim($content);

        if (! str_starts_with($content, '```')) {
            return $content;
        }

        $content = preg_replace('/^```[a-zA-Z]*\s*/', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;

        return trim($content);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function check(mixed $value, array $schema, string $path): array
    {
        $errors = [];
        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! $this->matchesType($value, $types)) {
            return [$this->label($path).':tipo_invalido'];
        }

        if (isset($schema['enum']) && $value !== null && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $this->label($path).':valor_fora_do_conjunto';
        }

        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
            $errors[] = $this->label($path).':texto_muito_longo';
        }

        if (is_numeric($value) && ! is_bool($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = $this->label($path).':abaixo_do_minimo';
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = $this->label($path).':acima_do_maximo';
            }
        }

        if (is_array($value) && ($schema['type'] ?? null) === 'array') {
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $errors[] = $this->label($path).':itens_demais';
            }

            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach (array_values($value) as $index => $item) {
                    $errors = array_merge($errors, $this->check($item, $schema['items'], $path.'['.$index.']'));
                }
            }
        }

        if (is_array($value) && ($schema['type'] ?? null) === 'object') {
            $errors = array_merge($errors, $this->checkObject($value, $schema, $path));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function checkObject(array $value, array $schema, string $path): array
    {
        $errors = [];
        $properties = (array) ($schema['properties'] ?? []);

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $value)) {
                $errors[] = $this->label($path.'.'.$required).':campo_obrigatorio_ausente';
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($value) as $key) {
                if (! array_key_exists($key, $properties)) {
                    $errors[] = $this->label($path.'.'.$key).':campo_desconhecido';
                }
            }
        }

        foreach ($properties as $key => $propertySchema) {
            if (array_key_exists($key, $value) && is_array($propertySchema)) {
                $errors = array_merge($errors, $this->check($value[$key], $propertySchema, $path.'.'.$key));
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, string>  $types
     */
    private function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object' => is_array($value) && ! array_is_list($value),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                // Inteiro tambem satisfaz `number`; booleano nunca satisfaz.
                'number' => (is_int($value) || is_float($value)) && ! is_bool($value),
                'integer' => is_int($value) && ! is_bool($value),
                'null' => $value === null,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        // Objeto vazio decodifica como lista vazia; aceitamos quando o schema
        // espera um objeto, para nao rejeitar `{}` legitimo.
        return in_array('object', $types, true) && $value === [];
    }

    private function label(string $path): string
    {
        return ltrim($path, '.') === '' ? 'raiz' : ltrim($path, '.');
    }

    /**
     * @param  array<int, string>  $errors
     * @return array{valid: bool, data: ?array<string, mixed>, errors: array<int, string>}
     */
    private function failure(array $errors): array
    {
        return ['valid' => false, 'data' => null, 'errors' => array_values(array_unique($errors))];
    }
}
