<x-layouts.app title="Configurações" breadcrumbs="Inicio / Configuracoes">
    <section class="card">
        <form method="post" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('put')
            <div class="grid grid-2">
                <p><label>Nome do sistema</label><input name="system[name]" value="{{ old('system.name', $settings['system.name'] ?? 'Gerenciador de Mensagens') }}" required></p>
                <p><label>Fuso horário</label><input name="system[timezone]" value="{{ old('system.timezone', $settings['system.timezone'] ?? 'America/Sao_Paulo') }}" required></p>
                <p><label>Formato de data</label><input name="system[date_format]" value="{{ old('system.date_format', $settings['system.date_format'] ?? 'd/m/Y') }}" required></p>
                <p><label>Formato de data e hora</label><input name="system[datetime_format]" value="{{ old('system.datetime_format', $settings['system.datetime_format'] ?? 'd/m/Y H:i') }}" required></p>
                <p><label>Registros por página</label><input name="system[records_per_page]" type="number" min="5" max="100" value="{{ old('system.records_per_page', $settings['system.records_per_page'] ?? 20) }}" required></p>
                <p>
                    <label>Dias na lixeira da Limpeza</label>
                    <input name="retention[cleanup_trash_days]" type="number" min="1" max="365" value="{{ old('retention.cleanup_trash_days', $settings['retention.cleanup_trash_days'] ?? 30) }}" required>
                    <span class="muted">Depois desse prazo, o que a Limpeza mandou para a lixeira é apagado em definitivo e não volta mais.</span>
                </p>
            </div>
            @can('manage-settings')
                <button class="btn" type="submit">Salvar configurações</button>
            @else
                <p class="muted">Você não tem permissão para alterar configurações.</p>
            @endcan
        </form>
    </section>
</x-layouts.app>
