@if (session('success'))
    <div class="alert success">{{ session('success') }}</div>
@endif

@if (session('status'))
    <div class="alert success">{{ session('status') }}</div>
@endif

@if (session('error'))
    <div class="alert error">{{ session('error') }}</div>
@endif

{{-- `$errors` e compartilhado pelo middleware de sessao. Uma pagina renderizada
     fora dele - o 404 de um endereco que nao casa com rota nenhuma, por exemplo
     - nao o recebe, e chamar `any()` em nulo derrubava a propria tela de erro. --}}
@if (isset($errors) && $errors->any())
    <div class="alert error">
        <strong>Corrija os campos destacados.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
