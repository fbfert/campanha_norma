<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);
        $this->withoutVite();

        /*
         | Nenhuma chamada externa de verdade sai de um teste.
         |
         | Isso era convenção, e convenção não impede nada. Um teste da rede de
         | segurança executou o comando de envio sem falsear o provedor, o
         | serviço do WhatsApp estava de pé, e a suíte mandou de verdade 132
         | mensagens ao longo de dois dias. Foram todas para o próprio número
         | conectado, então ninguém de fora recebeu nada — mas foi sorte do
         | endereço, não desenho.
         |
         | `preventStrayRequests` transforma o silêncio em erro: requisição que
         | nenhum `Http::fake()` cobre falha na hora, dizendo qual URL era. O
         | teste que precisa de rede passa a ter de dizer isso explicitamente.
         */
        Http::preventStrayRequests();
    }
}
