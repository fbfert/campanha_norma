<x-layouts.app title="Configuracoes" breadcrumbs="Inicio / Configuracoes">
    <section class="card">
        <form method="post" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('put')
            <div class="grid grid-2">
                <p><label>Nome do sistema</label><input name="system[name]" value="{{ old('system.name', $settings['system.name'] ?? 'Gerenciador de Mensagens') }}" required></p>
                <p><label>Fuso horario</label><input name="system[timezone]" value="{{ old('system.timezone', $settings['system.timezone'] ?? 'America/Sao_Paulo') }}" required></p>
                <p><label>Formato de data</label><input name="system[date_format]" value="{{ old('system.date_format', $settings['system.date_format'] ?? 'd/m/Y') }}" required></p>
                <p><label>Formato de data e hora</label><input name="system[datetime_format]" value="{{ old('system.datetime_format', $settings['system.datetime_format'] ?? 'd/m/Y H:i') }}" required></p>
                <p><label>Registros por pagina</label><input name="system[records_per_page]" type="number" min="5" max="100" value="{{ old('system.records_per_page', $settings['system.records_per_page'] ?? 20) }}" required></p>
            </div>
            @can('manage-settings')
                <button class="btn" type="submit">Salvar configuracoes</button>
            @else
                <p class="muted">Voce nao tem permissao para alterar configuracoes.</p>
            @endcan
        </form>
    </section>
</x-layouts.app>
