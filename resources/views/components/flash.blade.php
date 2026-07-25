@if (session('success'))
    <div class="alert success">{{ session('success') }}</div>
@endif

@if (session('status'))
    <div class="alert success">{{ session('status') }}</div>
@endif

@if (session('error'))
    <div class="alert error">{{ session('error') }}</div>
@endif

@if ($errors->any())
    <div class="alert error">
        <strong>Corrija os campos destacados.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
