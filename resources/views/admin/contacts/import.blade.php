<x-layouts.app title="Importar contatos" breadcrumbs="Inicio / Contatos / Importar">
    <section class="card">
        <h2>Enviar arquivo</h2>
        <p class="muted">Formatos aceitos: CSV e XLSX. A importação não cria contatos antes da confirmação.</p>
        <form method="post" action="{{ route('admin.contacts.import.upload') }}" enctype="multipart/form-data">
            @csrf
            <p><label>Arquivo</label><input name="file" type="file" accept=".csv,.xlsx" required></p>
            <button class="btn" type="submit">Enviar e pré-validar</button>
            <a class="btn ghost" href="{{ route('admin.contacts.import.template') }}">Baixar modelo</a>
        </form>
    </section>
</x-layouts.app>
