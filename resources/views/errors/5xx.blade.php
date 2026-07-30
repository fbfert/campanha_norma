@php
    $status = $exception?->getStatusCode() ?? 500;

    // Nada de detalhe tecnico aqui. Mensagem de erro interno costuma carregar
    // caminho de arquivo, nome de tabela ou trecho de consulta, e a pagina de
    // erro e publica.
    [$title, $message] = match ($status) {
        503 => ['Sistema em manutencao', 'Estamos aplicando uma atualizacao. Tente novamente em alguns minutos.'],
        default => ['Algo deu errado do nosso lado', 'A falha foi registrada. Se ela se repetir, avise quem cuida do sistema informando o horario em que aconteceu.'],
    };
@endphp

@include('errors._page', ['status' => $status, 'title' => $title, 'message' => $message])
