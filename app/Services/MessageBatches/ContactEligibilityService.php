<?php

namespace App\Services\MessageBatches;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Services\Placeholders\MessageRendererService;

class ContactEligibilityService
{
    public function __construct(private readonly MessageRendererService $renderer) {}

    public function evaluate(Contact $contact, string $messageBody): array
    {
        $errors = [];

        if ($contact->trashed()) {
            $errors[] = 'Contato excluído.';
        }
        if ($contact->status === ContactStatus::Inactive) {
            $errors[] = 'Contato inativo.';
        }
        if ($contact->status === ContactStatus::Blocked) {
            $errors[] = 'Contato bloqueado.';
        }
        if ($contact->do_not_contact) {
            $errors[] = 'Contato marcado como não contatar.';
        }
        if (blank($contact->phone_normalized)) {
            $errors[] = 'Telefone valido ausente.';
        }

        $render = $this->renderer->render($messageBody, $contact);
        $errors = array_merge($errors, $render['errors']);

        return [
            'eligible' => $errors === [],
            'reason' => implode(' ', array_unique($errors)),
            'rendered_message' => $render['message'],
            'render_errors' => $errors,
        ];
    }
}
