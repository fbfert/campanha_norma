<x-layouts.app title="Base de conhecimento" breadcrumbs="Inicio / Base de conhecimento">
    <section class="card">
        <p class="muted">
            Bases oficiais aprovadas. Somente documento aprovado dentro de base ativa pode ser recuperado para fundamentar uma resposta.
        </p>
        <p class="muted">
            Recuperação: <strong>{{ $knowledgeEnabled ? 'ligada' : 'desligada' }}</strong>.
            Estratégia configurada: <strong>{{ $strategy->label() }}</strong>.
            @unless($knowledgeEnabled)
                Com a recuperação desligada nada da base entra no contexto da IA.
            @endunless
        </p>
        @can('knowledge.manage_bases')
            <div class="actions">
                <a class="btn" href="{{ route('admin.knowledge.bases.create') }}">Nova base</a>
            </div>
        @endcan
    </section>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    <section class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Base</th>
                    <th>Situação</th>
                    <th>Documentos</th>
                    <th>Aprovados</th>
                    <th>Fluxos</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($bases as $base)
                    <tr>
                        <td>
                            <strong>{{ $base->name }}</strong>
                            <div class="muted">{{ $base->description }}</div>
                        </td>
                        <td>{{ $base->status->label() }}</td>
                        <td>{{ $base->documents_count }}</td>
                        <td>{{ $base->approved_documents_count }}</td>
                        <td>{{ $base->flows()->count() }}</td>
                        <td class="actions">
                            <a class="btn ghost" href="{{ route('admin.knowledge.bases.show', $base) }}">Abrir</a>
                            @can('knowledge.manage_bases')
                                <a class="btn ghost" href="{{ route('admin.knowledge.bases.edit', $base) }}">Editar</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Nenhuma base cadastrada.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $bases->links() }}
    </section>
</x-layouts.app>
