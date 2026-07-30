@php
    // O Laravel procura `errors/{codigo}` e cai neste arquivo quando nao acha.
    // Um mapa aqui evita um arquivo quase identico por codigo.
    $status = $exception?->getStatusCode() ?? 400;

    [$title, $message] = match ($status) {
        401 => ['Sessao encerrada', 'Entre novamente para continuar de onde parou.'],
        403 => ['Sem permissao', 'Seu perfil nao tem acesso a esta tela. Se voce precisa dela para trabalhar, peca a um administrador.'],
        404 => ['Pagina nao encontrada', 'O endereco nao existe, ou o registro que estava aqui foi removido.'],
        419 => ['A pagina expirou', 'O formulario ficou aberto tempo demais. Abra a tela de novo e refaca a acao - nada foi salvo.'],
        429 => ['Pedidos demais', 'Espere alguns instantes antes de tentar outra vez.'],
        default => ['Nao foi possivel abrir', 'O sistema nao conseguiu atender a este pedido.'],
    };
@endphp

@include('errors._page', ['status' => $status, 'title' => $title, 'message' => $message])
