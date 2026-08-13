<x-layouts.app title="Novo perfil de atendimento" breadcrumbs="Inicio / Atendimento de entrada / Perfis / Novo">
    <form method="post" action="{{ route('admin.inbound-attendance.profiles.store') }}">
        @csrf
        @include('admin.inbound-attendance.profiles._form')
        <div class="actions" style="margin-top:16px;">
            <button class="btn" type="submit">Criar perfil</button>
            <a class="btn ghost" href="{{ route('admin.inbound-attendance.profiles.index') }}">Cancelar</a>
        </div>
    </form>
</x-layouts.app>
