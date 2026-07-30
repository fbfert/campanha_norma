<x-layouts.app title="Enviar documento" breadcrumbs="Inicio / Base de conhecimento / Base / Novo documento">
    @if($errors->any())
        <div class="alert error">
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="card">
        <p class="muted">
            Envie apenas conteúdo oficial e já aprovado para uso público. Não envie conversa de cidadão, opinião coletada na pesquisa,
            dado pessoal ou material que dependa de autorização.
        </p>
        <p class="muted">
            Tipos aceitos: {{ implode(', ', $acceptedMimeTypes) }}. Tamanho máximo: {{ number_format($maxFileSizeKb / 1024, 1, ',', '.') }} MB.
        </p>

        <form method="post" action="{{ route('admin.knowledge.documents.store', $base) }}" enctype="multipart/form-data">
            @csrf

            <div>
                <label for="title">Título</label>
                <input id="title" name="title" type="text" maxlength="255" required value="{{ old('title') }}">
            </div>

            <div>
                <label for="type">Tipo de conteúdo</label>
                <select id="type" name="type" required>
                    @foreach($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
                <p class="muted">Somente estes tipos são permitidos na base oficial.</p>
            </div>

            <div class="grid grid-3">
                <div>
                    <label for="source">Origem</label>
                    <input id="source" name="source" type="text" maxlength="255" value="{{ old('source') }}">
                </div>
                <div>
                    <label for="source_url">URL de origem</label>
                    <input id="source_url" name="source_url" type="url" maxlength="2048" value="{{ old('source_url') }}">
                </div>
                <div>
                    <label for="document_date">Data do documento</label>
                    <input id="document_date" name="document_date" type="date" value="{{ old('document_date') }}">
                </div>
            </div>

            <div class="grid grid-2">
                <div>
                    <label for="version">Versão</label>
                    <input id="version" name="version" type="number" min="1" max="65535" value="{{ old('version', 1) }}">
                </div>
                <div>
                    <label for="supersedes_document_id">Substitui o documento</label>
                    <select id="supersedes_document_id" name="supersedes_document_id">
                        <option value="">Nenhum</option>
                        @foreach($candidates as $candidate)
                            <option value="{{ $candidate->id }}" @selected(old('supersedes_document_id') == $candidate->id)>{{ $candidate->title }}</option>
                        @endforeach
                    </select>
                    <p class="muted">O documento substituído vira obsoleto quando este for aprovado.</p>
                </div>
            </div>

            <div>
                <label for="file">Arquivo</label>
                <input id="file" name="file" type="file" required>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Enviar</button>
                <a class="btn ghost" href="{{ route('admin.knowledge.bases.show', $base) }}">Cancelar</a>
            </div>
        </form>
    </section>
</x-layouts.app>
