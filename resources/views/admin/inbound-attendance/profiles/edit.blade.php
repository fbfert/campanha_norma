<x-layouts.app title="Editar perfil de atendimento" breadcrumbs="Inicio / Atendimento de entrada / Perfis / Editar">
    <form method="post" action="{{ route('admin.inbound-attendance.profiles.update', $profile) }}">
        @csrf
        @method('put')
        @include('admin.inbound-attendance.profiles._form')
        <div class="actions" style="margin-top:16px;">
            <button class="btn" type="submit">Salvar</button>
            <a class="btn ghost" href="{{ route('admin.inbound-attendance.profiles.index') }}">Cancelar</a>
        </div>
    </form>

    @can('inbound_attendance.manage_profiles')
        <form method="post" action="{{ route('admin.inbound-attendance.profiles.destroy', $profile) }}" style="margin-top:16px;">
            @csrf
            @method('delete')
            <button class="btn secondary" type="submit">Excluir perfil</button>
        </form>
    @endcan
</x-layouts.app>
