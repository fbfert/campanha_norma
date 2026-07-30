<x-layouts.app :title="$base->name" breadcrumbs="Inicio / Base de conhecimento / Base">
    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert error">
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="card">
        <p class="muted">{{ $base->description }}</p>
        <dl class="grid grid-3">
            <div><dt>Situacao</dt><dd>{{ $base->status->label() }}</dd></div>
            <div><dt>Versao</dt><dd>{{ $base->version }}</dd></div>
            <div><dt>Provedor</dt><dd>{{ $base->provider }}</dd></div>
            <div><dt>Documentos aprovados</dt><dd>{{ $base->approvedDocumentCount() }}</dd></div>
            <div><dt>Aprovada em</dt><dd>{{ $base->approved_at?->format($dateTimeFormat) ?: 'Nao aprovada' }}</dd></div>
            <div><dt>Fluxos associados</dt><dd>{{ $base->flows->pluck('name')->implode(', ') ?: 'Nenhum' }}</dd></div>
        </dl>

        @if($base->usage_policy)
            <h3>Politica de uso</h3>
            <p>{{ $base->usage_policy }}</p>
        @endif

        <div class="actions">
            @can('knowledge.manage_bases')
                <a class="btn ghost" href="{{ route('admin.knowledge.bases.edit', $base) }}">Editar</a>
                <form method="post" action="{{ route('admin.knowledge.bases.status', $base) }}" class="inline">
                    @csrf
                    <select name="status" aria-label="Nova situacao">
                        @foreach(\App\Enums\KnowledgeBaseStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected($base->status === $status)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                    <button class="btn" type="submit">Alterar situacao</button>
                </form>
            @endcan
            @can('knowledge.upload_documents')
                <a class="btn" href="{{ route('admin.knowledge.documents.create', $base) }}">Enviar documento</a>
            @endcan
        </div>
        <p class="muted">
            Ativar a base torna os documentos aprovados dela alcancaveis pela busca. Desativar interrompe a recuperacao sem apagar nada.
        </p>
    </section>

    <section class="card">
        <h2>Documentos</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Titulo</th>
                    <th>Tipo</th>
                    <th>Situacao</th>
                    <th>Trechos</th>
                    <th>Enviado em</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td>
                            {{ $document->title }}
                            @if($document->injection_flagged)
                                <span class="badge warning" title="Instrucao detectada e neutralizada na ingestao">instrucao detectada</span>
                            @endif
                        </td>
                        <td>{{ $document->type?->label() }}</td>
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
