<x-layouts.app title="Previa do modelo" breadcrumbs="Inicio / Mensagens / Modelos / Previa">
    <section class="card">
        <h2>{{ $contact->name }}</h2>
        <p><strong>Placeholders:</strong> {{ implode(', ', $result['placeholders']) ?: 'Nenhum' }}</p>
        <p><strong>Campos vazios:</strong> {{ implode(', ', $result['missing']) ?: 'Nenhum' }}</p>
        @if($result['errors'])<div class="alert error">{{ implode(' ', $result['errors']) }}</div>@endif
        <pre style="white-space:pre-wrap;">{{ $result['message'] }}</pre>
    </section>
</x-layouts.app>
