<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProvider;
use InvalidArgumentException;

class WhatsAppProviderManager
{
    public function provider(): WhatsAppProvider
    {
        return match (config('whatsapp.provider')) {
            'web' => app(WhatsAppWebProvider::class),
            'meta' => app(MetaCloudProvider::class),
            default => throw new InvalidArgumentException('Provedor de WhatsApp não suportado.'),
        };
    }
}
