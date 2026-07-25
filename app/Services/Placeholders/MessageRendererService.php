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

        foreach ($parsed['valid'] as $placeholder) {
            $value = $this->catalog->value($contact, $placeholder);
            if ($value === null || $value === '') {
                $missing[] = $placeholder;
                $errors[] = 'O campo '.$this->catalog->label($placeholder).' e obrigatorio para esta mensagem.';

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
            'errors' => $errors,
        ];
    }
}
