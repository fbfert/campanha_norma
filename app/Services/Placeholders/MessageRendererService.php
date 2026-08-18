<?php

namespace App\Services\Placeholders;

use App\Models\Contact;
use App\Services\SystemSettingService;

class MessageRendererService
{
    public function __construct(
        private readonly PlaceholderParserService $parser,
        private readonly PlaceholderCatalogService $catalog,
        private readonly SystemSettingService $settings,
    ) {}

    public function render(string $body, Contact $contact): array
    {
        [$parsed, $errors] = $this->parser->validate($body);
        $rendered = str_replace(["\r\n", "\r"], "\n", $body);
        $missing = [];
        $substituidos = [];

        foreach ($parsed['valid'] as $placeholder) {
            $value = $this->catalog->value($contact, $placeholder);

            if ($value === null || $value === '') {
                /*
                 | Campo vazio com substituto vira o substituto, e não recusa.
                 |
                 | Só para os campos em que uma palavra genérica funciona na
                 | frase — hoje, a cidade. Para nome, telefone ou e-mail a
                 | recusa continua: não há genérico que sirva, e mandar o
                 | placeholder literal é pior que não mandar.
                 */
                $substituto = $this->catalog->fallback($placeholder);

                if ($substituto !== null) {
                    $rendered = str_replace('{'.$placeholder.'}', $substituto, $rendered);
                    $substituidos[] = $placeholder;

                    continue;
                }

                $missing[] = $placeholder;
                $errors[] = 'O campo '.$this->catalog->label($placeholder).' e obrigatório para esta mensagem.';

                continue;
            }

            $rendered = str_replace('{'.$placeholder.'}', $value, $rendered);
        }

        $maximum = (int) $this->settings->get('messages.maximum_length', 4096);
        if (mb_strlen($rendered) > $maximum) {
            $errors[] = "A mensagem renderizada ultrapassa {$maximum} caracteres.";
        }

        return [
            'message' => $rendered,
            'placeholders' => $parsed['valid'],
            'missing' => $missing,

            // Campos que saíram pelo substituto genérico. Quem monta a
            // mensagem pode querer avisar; quem envia não precisa saber.
            'fallbacks' => $substituidos,
            'errors' => $errors,
        ];
    }
}
