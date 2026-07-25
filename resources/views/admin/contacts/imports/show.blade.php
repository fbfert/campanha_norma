<x-layouts.app title="Detalhes da importação" breadcrumbs="Inicio / Contatos / Importações / Detalhes">
    <section class="card">
        <h2>{{ $import->original_filename }}</h2>
        <div class="grid grid-3">
            <p><strong>Status:</strong> {{ $import->status->label() }}</p>
            <p><strong>Total de linhas:</strong> {{ $import->total_rows }}</p>
            <p><strong>Linhas válidas:</strong> {{ $import->valid_rows }}</p>
            <p><strong>Linhas com erro:</strong> {{ $import->invalid_rows }}</p>
            <p><strong>Duplicados:</strong> {{ $import->duplicate_rows }}</p>
            <p><strong>Criados:</strong> {{ $import->created_rows }}</p>
            <p><strong>Atualizados:</strong> {{ $import->updated_rows }}</p>
            <p><strong>Ignorados:</strong> {{ $import->ignored_rows }}</p>
        </div>
        <div class="actions">
            <form method="post" action="{{ route('admin.contacts.imports.validate', $import) }}">@csrf <button class="btn" type="submit">Pré-validar</button></form>
            <form method="post" action="{{ route('admin.contacts.imports.confirm', $import) }}">@csrf <select name="duplicate_strategy" style="width:auto;"><option value="ignore">Ignorar duplicados</option><option value="update">Atualizar existentes</option><option value="new_only">Criar apenas novos</option><option value="interrupt">Interromper ao duplicado</option></select><button class="btn secondary" type="submit">Confirmar processamento</button></form>
        </div>
    </section>
    <section class="card table-wrap" style="margin-top:16px;">
        <table>
            <thead><tr><th>Linha</th><th>Status</th><th>Dados</th><th>Problemas</th></tr></thead>
            <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row->row_number }}</td>
                    <td>{{ $row->status->value }}</td>
                    <td><pre>{{ json_encode($row->normalized_data ?? $row->raw_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre></td>
                    <td><pre>{{ json_encode($row->error_messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        {{ $rows->links() }}
    </section>
</x-layouts.app>
