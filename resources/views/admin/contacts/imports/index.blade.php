<x-layouts.app title="Histórico de importações" breadcrumbs="Inicio / Contatos / Importações">
    <section class="card table-wrap">
        @if($imports->isEmpty())
            <p class="muted">Nenhuma importação registrada.</p>
        @else
            <table>
                <thead><tr><th>Arquivo</th><th>Responsável</th><th>Data</th><th>Status</th><th>Total</th><th>Criados</th><th>Atualizados</th><th>Ignorados</th><th>Erros</th><th>Ações</th></tr></thead>
                <tbody>
                @foreach($imports as $import)
                    <tr>
                        <td>{{ $import->original_filename }}</td>
                        <td>{{ $import->user?->name }}</td>
                        <td>{{ $import->created_at->format($dateTimeFormat) }}</td>
                        <td>{{ $import->status->label() }}</td>
                        <td>{{ $import->total_rows }}</td>
                        <td>{{ $import->created_rows }}</td>
                        <td>{{ $import->updated_rows }}</td>
                        <td>{{ $import->ignored_rows }}</td>
                        <td>{{ $import->error_rows }}</td>
                        <td><a class="btn ghost" href="{{ route('admin.contacts.imports.show', $import) }}">Detalhes</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $imports->links() }}
        @endif
    </section>
</x-layouts.app>
