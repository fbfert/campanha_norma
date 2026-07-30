@php
    $status = $exception?->getStatusCode() ?? 500;

    // Nada de detalhe técnico aqui. Mensagem de erro interno costuma carregar
    // caminho de arquivo, nome de tabela ou trecho de consulta, e a página de
    // erro e pública.
    [$title, $message] = match ($status) {
        503 => ['Sistema em manutenção', 'Estamos aplicando uma atualização. Tente novamente em alguns minutos.'],
        default => ['Algo deu errado do nosso lado', 'A falha foi registrada. Se ela se repetir, avise quem cuida do sistema informando o horário em que aconteceu.'],
    };
@endphp

@include('errors._page', ['status' => $status, 'title' => $title, 'message' => $message])
