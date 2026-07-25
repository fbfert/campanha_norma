<x-layouts.app title="Etiquetas" breadcrumbs="Inicio / Contatos / Etiquetas">
    <div class="actions" style="margin-bottom:16px;"><a class="btn" href="{{ route('admin.tags.create') }}">Nova etiqueta</a></div>
    <section class="card table-wrap">
        @if($tags->isEmpty())
            <p class="muted">Nenhuma etiqueta cadastrada.</p>
        @else
            <table>
                <thead><tr><th>Nome</th><th>Cor</th><th>Situação</th><th>Contatos</th><th>Ações</th></tr></thead>
                <tbody>
                @foreach($tags as $tag)
                    <tr>
                        <td>{{ $tag->name }}</td>
                        <td><span style="display:inline-block;background:{{ $tag->color }};color:#fff;border-radius:6px;padding:3px 8px;">{{ $tag->color }}</span></td>
                        <td>{{ $tag->is_active ? 'Ativa' : 'Inativa' }}</td>
                        <td>{{ $tag->contacts_count }}</td>
                        <td class="actions"><a class="btn ghost" href="{{ route('admin.tags.edit', $tag) }}">Editar</a><form method="post" action="{{ route('admin.tags.destroy', $tag) }}" onsubmit="return confirm('Excluir logicamente esta etiqueta?')">@csrf @method('delete')<button class="btn danger" type="submit">Excluir</button></form></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $tags->links() }}
        @endif
    </section>
</x-layouts.app>
