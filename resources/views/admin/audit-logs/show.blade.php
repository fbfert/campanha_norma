<x-layouts.app title="Detalhes da auditoria" breadcrumbs="Inicio / Auditoria / Detalhes">
    <section class="card">
        <p><strong>Data:</strong> {{ $auditLog->created_at->format($dateTimeFormat) }}</p>
        <p><strong>Usuario:</strong> {{ $auditLog->user?->name ?? 'Sistema' }}</p>
        <p><strong>Acao:</strong> {{ $auditLog->action }}</p>
        <p><strong>Entidade:</strong> {{ $auditLog->entity_type }} #{{ $auditLog->entity_id }}</p>
        <p><strong>Descricao:</strong> {{ $auditLog->description }}</p>
        <p><strong>IP:</strong> {{ $auditLog->ip_address }}</p>
        <p><strong>Navegador:</strong> {{ $auditLog->user_agent }}</p>
        <h2>Valores anteriores</h2>
        <pre>{{ json_encode($auditLog->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        <h2>Valores posteriores</h2>
        <pre>{{ json_encode($auditLog->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        <a class="btn ghost" href="{{ route('admin.audit-logs.index') }}">Voltar</a>
    </section>
</x-layouts.app>
