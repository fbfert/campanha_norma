{{--
    Corpo comum das paginas de erro.

    Ate agora o sistema caia na pagina padrao do Laravel, escrita em utilitarias
    do Tailwind que nenhuma tela usa. Ela so aparecia estilizada porque as
    classes chegavam ao CSS pela view ja compilada em cache - quer dizer, um
    `view:clear` antes de um build deixava o 404 sem estilo, e ninguem
    descobriria ate alguem errar um endereco.

    Estas paginas usam as mesmas classes do resto do sistema. Alem de resolver
    aquilo, quem se perde passa a ver algo que parece o sistema, e em portugues.
--}}
<x-layouts.guest>
    <p class="muted" style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;margin:0 0 4px;">Erro {{ $status }}</p>
    <h2 style="margin-top:0;">{{ $title }}</h2>
    <p class="muted">{{ $message }}</p>
    {{-- Sem icone: o layout de visitante nao carrega o sprite, e carregar o
         sprite inteiro por causa de um botao nao se paga. --}}
    <div class="actions" style="margin-top:16px;">
        @auth
            <a class="btn" href="{{ route('dashboard') }}">Voltar ao inicio</a>
        @else
            <a class="btn" href="{{ route('login') }}">Ir para o login</a>
        @endauth
    </div>
</x-layouts.guest>
