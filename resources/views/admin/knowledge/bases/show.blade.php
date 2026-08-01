<x-layouts.app :title="$base->name" breadcrumbs="Início / Base de conhecimento / Base">
    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif
    <section class="card">
        <p class="muted">{{ $base->description }}</p>
        <dl class="grid grid-3">
            <div><dt>Situação</dt><dd>{{ $base->status->label() }}</dd></div>
            <div><dt>Versão</dt><dd>{{ $base->version }}</dd></div>
            <div><dt>Provedor</dt><dd>{{ $base->provider }}</dd></div>
            <div><dt>Documentos aprovados</dt><dd>{{ $base->approvedDocumentCount() }}</dd></div>
            <div><dt>Aprovada em</dt><dd>{{ $base->approved_at?->format($dateTimeFormat) ?: 'Não aprovada' }}</dd></div>
            <div><dt>Fluxos associados</dt><dd>{{ $base->flows->pluck('name')->implode(', ') ?: 'Nenhum' }}</dd></div>
        </dl>

        @if($base->usage_policy)
            <h3>Política de uso</h3>
            <p>{{ $base->usage_policy }}</p>
        @endif

        <div class="actions">
            @can('knowledge.manage_bases')
                <a class="btn ghost" href="{{ route('admin.knowledge.bases.edit', $base) }}">Editar</a>
                <form method="post" action="{{ route('admin.knowledge.bases.status', $base) }}" class="inline">
                    @csrf
                    <select name="status" aria-label="Nova situação">
                        @foreach(\App\Enums\KnowledgeBaseStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected($base->status === $status)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                    <button class="btn" type="submit">Alterar situação</button>
                </form>
            @endcan
            @can('knowledge.upload_documents')
                <a class="btn" href="{{ route('admin.knowledge.documents.create', $base) }}">Enviar documento</a>
            @endcan
        </div>
        <p class="muted">
            Ativar a base torna os documentos aprovados dela alcancáveis pela busca. Desativar interrompe a recuperação sem apagar nada.
        </p>
    </section>

    <section class="card">
        {{-- O escopo vai escrito no título. A tabela sempre listou só esta base,
             mas a coluna "Tipo" ao lado do título fazia "Biografia aprovada"
             parecer o nome de um segundo documento. --}}
        <h2>Documentos desta base ({{ $documents->total() }})</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Tipo</th>
                    <th>Situação</th>
                    <th>Trechos</th>
                    <th>Enviado em</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td>
                            <strong>{{ $document->title }}</strong>
                            @if($document->injection_flagged)
                                <span class="badge warning" title="Instrução detectada e neutralizada na ingestão">instrução detectada</span>
                            @endif
                        </td>
                        <td class="muted">{{ $document->type?->label() }}</td>
                        <td>
                            {{ $document->status->label() }}
                            @if($document->error_message)
                                <div class="muted">{{ $document->error_message }}</div>
                            @endif
                        </td>
                        <td>{{ $document->chunk_count }}</td>
                        <td>{{ $document->created_at?->format($dateTimeFormat) }}</td>
                        <td><a class="btn ghost" href="{{ route('admin.knowledge.documents.show', [$base, $document]) }}">Abrir</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Nenhum documento nesta base.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $documents->links() }}
    </section>
</x-layouts.app>
