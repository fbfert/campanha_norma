@php
    // O Laravel procura `errors/{código}` e cai neste arquivo quando não acha.
    // Um mapa aqui evita um arquivo quase identico por código.
    $status = $exception?->getStatusCode() ?? 400;

    [$title, $message] = match ($status) {
        401 => ['Sessão encerrada', 'Entre novamente para continuar de onde parou.'],
        403 => ['Sem permissão', 'Seu perfil não tem acesso a esta tela. Se você precisa dela para trabalhar, peca a um administrador.'],
        404 => ['Página não encontrada', 'O endereço não existe, ou o registro que estava aqui foi removido.'],
        419 => ['A página expirou', 'O formulário ficou aberto tempo demais. Abra a tela de novo e refaca a ação - nada foi salvo.'],
        429 => ['Pedidos demais', 'Espere alguns instantes antes de tentar outra vez.'],
        default => ['Não foi possível abrir', 'O sistema não conseguiu atender a este pedido.'],
    };
@endphp

@include('errors._page', ['status' => $status, 'title' => $title, 'message' => $message])
