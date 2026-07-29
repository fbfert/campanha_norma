<x-layouts.app title="Temas de insights" breadcrumbs="Inicio / Pesquisa conversacional / Temas">
    <section class="card">
        <p class="muted">Taxonomia usada para agrupar as respostas. O modelo nunca cria temas: a saida livre e mapeada para um tema cadastrado ou para o tema de fallback.</p>
        @can('ai_insights.manage_taxonomy')
            <div class="actions"><a class="btn" href="{{ route('admin.insight-topics.create') }}">Novo tema</a></div>
        @endcan
    </section>

    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Ordem</th><th>Tema</th><th>Identificador</th><th>Pai</th><th>Sinonimos</th><th>Insights</th><th>Situacao</th><th>Acoes</th></tr>
                </thead>
                <tbody>
                    @forelse($topics as $topic)
                        <tr>
                            <td>{{ $topic->display_order }}</td>
                            <td>
                                <span class="badge" style="background:{{ $topic->color ?? '#64748b' }};color:#fff;">{{ $topic->name }}</span>
                                @if($topic->is_fallback)<span class="muted"> fallback</span>@endif
                            </td>
                            <td><code>{{ $topic->slug }}</code></td>
                            <td>{{ $topic->parent?->name ?? '-' }}</td>
                            <td>{{ Str::limit($topic->synonyms ?? '-', 60) }}</td>
                            <td>{{ $topic->insights_count }}</td>
                            <td>{{ $topic->is_active ? 'Ativo' : 'Inativo' }}</td>
                            <td class="actions">
                                @can('ai_insights.manage_taxonomy')
                                    <a class="btn ghost" href="{{ route('admin.insight-topics.edit', $topic) }}">Editar</a>
                                    @unless($topic->is_fallback)
                                        <form method="post" action="{{ route('admin.insight-topics.destroy', $topic) }}" onsubmit="return confirm('Excluir este tema?')">
                                            @csrf @method('delete')
                                            <button class="btn danger">Excluir</button>
                                        </form>
                                    @endunless
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Nenhum tema cadastrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $topics->links() }}
    </section>
</x-layouts.app>
