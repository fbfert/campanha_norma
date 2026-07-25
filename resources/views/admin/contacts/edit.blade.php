<x-layouts.app title="Editar contato" breadcrumbs="Inicio / Contatos / Editar">
    @if($contact->do_not_contact)<div class="alert error">Este contato está marcado como não contatar.</div>@endif
    <section class="card">
        <form method="post" action="{{ route('admin.contacts.update', $contact) }}">
            @csrf
            @method('put')
            @include('admin.contacts._form')
            <button class="btn" type="submit">Salvar contato</button>
            <a class="btn ghost" href="{{ route('admin.contacts.show', $contact) }}">Cancelar</a>
        </form>
    </section>
    @include('admin.contacts.history', ['history' => $contact->history])
</x-layouts.app>
