<x-layouts.app :title="$document->title" breadcrumbs="Início / Base de conhecimento / Base / Documento">
    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert error">
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="card">
        <dl class="grid grid-3">
            <div><dt>Base</dt><dd><a href="{{ route('admin.knowledge.bases.show', $base) }}">{{ $base->name }}</a></dd></div>
            <div><dt>Situação</dt><dd>{{ $document->status->label() }}</dd></div>
            <div><dt>Tipo</dt><dd>{{ $document->type?->label() }}</dd></div>
            <div><dt>Versão</dt><dd>{{ $document->version }}</dd></div>
            <div><dt>Trechos</dt><dd>{{ $document->chunk_count }}</dd></div>
            <div><dt>Arquivo</dt><dd>{{ $document->original_filename }} ({{ $document->mime_type }})</dd></div>
            <div><dt>Indexado em</dt><dd>{{ $document->indexed_at?->format($dateTimeFormat) ?: 'Não indexado' }}</dd></div>
            <div><dt>Aprovado em</dt><dd>{{ $document->approved_at?->format($dateTimeFormat) ?: 'Não aprovado' }}</dd></div>
            <div><dt>Antivirus</dt><dd>{{ $document->antivirus_result ?: 'Sem registro' }}</dd></div>
            @if($document->supersedes)
                <div><dt>Substitui</dt><dd>{{ $document->supersedes->title }}</dd></div>
            @endif
            @if($document->error_message)
                <div><dt>Erro</dt><dd>{{ $document->error_message }}</dd></div>
            @endif
        </dl>

        @if($document->injection_flagged)
            <div class="alert warning">
                <strong>Instrução detectada neste documento e neutralizada na ingestão.</strong>
                <p class="muted">O trecho suspeito foi substituído antes de virar conteúdo recuperável. Revise o material antes de aprovar.</p>
                <ul>
                    @foreach((array) $document->injection_findings as $finding)
                        <li>{{ is_array($finding) ? json_encode($finding, JSON_UNESCAPED_UNICODE) : $finding }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="actions">
            @can('knowledge.approve_documents')
                @if($document->status->canBeApproved())
                    <form method="post" action="{{ route('admin.knowledge.documents.approve', [$base, $document]) }}" class="inline">
                        @csrf
                        <button class="btn" type="submit">Aprovar para uso</button>
                    </form>
                @endif
                <form method="post" action="{{ route('admin.knowledge.documents.reject', [$base, $document]) }}" class="inline">
                    @csrf
                    <input type="text" name="reason" maxlength="255" placeholder="Motivo da rejeição">
                    <button class="btn ghost" type="submit">Rejeitar</button>
                </form>
                <form method="post" action="{{ route('admin.knowledge.documents.obsolete', [$base, $document]) }}" class="inline">
                    @csrf
                    <input type="text" name="reason" maxlength="255" placeholder="Motivo da obsolescência">
                    <button class="btn ghost" type="submit">Marcar como obsoleto</button>
                </form>
            @endcan
            @can('knowledge.upload_documents')
                @if($document->status->canBeReprocessed())
                    <form method="post" action="{{ route('admin.knowledge.documents.reprocess', [$base, $document]) }}" class="inline">
                        @csrf
                        <button class="btn ghost" type="submit">Reprocessar</button>
                    </form>
                @endif
            @endcan
            @can('knowledge.download_documents')
                <a class="btn ghost" href="{{ route('admin.knowledge.documents.download', [$base, $document]) }}">Baixar original</a>
            @endcan
            @can('knowledge.delete_documents')
                <form method="post" action="{{ route('admin.knowledge.documents.destroy', [$base, $document]) }}" class="inline"
                      onsubmit="return confirm('Excluir o documento? As citacoes ja registradas continuam existindo com o conteudo que sustentou cada resposta.');">
                    @csrf @method('delete')
                    <button class="btn danger" type="submit">Excluir</button>
                </form>
            @endcan
        </div>
        <p class="muted">Aprovar e o único ato que torna este documento alcancável pela busca. Reprocessar revoga a aprovação anterior.</p>
    </section>

    <section class="card">
        <h2>Texto extraido</h2>
        <p class="muted">Previa do que a ingestão leu do arquivo, já com as instruções suspeitas neutralizadas.</p>
        <pre class="preformatted">{{ $extractPreview ?: 'Sem texto extraido.' }}</pre>
    </section>

    <section class="card">
        <h2>Trechos indexados</h2>
        <p class="muted">São estes os trechos que podem ser citados numa resposta.</p>
        @forelse($chunks as $chunk)
            <article class="card nested">
                <p class="muted">
                    #{{ $chunk->chunk_index }}
                    @if($chunk->page) &middot; página {{ $chunk->page }} @endif
                    @if($chunk->section) &middot; seção {{ $chunk->section }} @endif
                    &middot; {{ $chunk->token_estimate }} tokens estimados
                    @if($chunk->embedded_at) &middot; vetor de {{ $chunk->embedding_dimensions }} dimensões @endif
                </p>
                <p>{{ $chunk->content }}</p>
            </article>
        @empty
            <p class="muted">Nenhum trecho indexado.</p>
        @endforelse
        {{ $chunks->links() }}
    </section>
</x-layouts.app>
