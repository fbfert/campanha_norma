<x-layouts.app title="Contatos" breadcrumbs="Inicio / Contatos">
    <form method="get" class="card" style="margin-bottom:16px;">
        <div class="grid grid-3">
            <p><label>Busca rápida</label><input name="q" value="{{ $filters['q'] ?? '' }}"></p>
            <p><label>Status</label><select name="status"><option value="">Todos</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></p>
            <p><label>Etiqueta</label><select name="tag_id"><option value="">Todas</option>@foreach($tags as $tag)<option value="{{ $tag->id }}" @selected(($filters['tag_id'] ?? '') == $tag->id)>{{ $tag->name }}</option>@endforeach</select></p>
            <p><label>Cidade</label><input name="city" value="{{ $filters['city'] ?? '' }}"></p>
            <p><label>Estado</label><input name="state" value="{{ $filters['state'] ?? '' }}"></p>
            <p><label>Não contatar</label><select name="do_not_contact"><option value="">Todos</option><option value="1" @selected(($filters['do_not_contact'] ?? '') === '1')>Sim</option><option value="0" @selected(($filters['do_not_contact'] ?? '') === '0')>Não</option></select></p>
            <p><label>Telefone</label><select name="phone_presence"><option value="">Todos</option><option value="with" @selected(($filters['phone_presence'] ?? '') === 'with')>Com telefone</option><option value="without" @selected(($filters['phone_presence'] ?? '') === 'without')>Sem telefone</option></select></p>
            <p><label>E-mail</label><select name="email_presence"><option value="">Todos</option><option value="with" @selected(($filters['email_presence'] ?? '') === 'with')>Com e-mail</option><option value="without" @selected(($filters['email_presence'] ?? '') === 'without')>Sem e-mail</option></select></p>
            <p><label>Excluídos</label><select name="deleted"><option value="">Ocultar</option><option value="with" @selected(($filters['deleted'] ?? '') === 'with')>Incluir</option><option value="only" @selected(($filters['deleted'] ?? '') === 'only')>Somente excluídos</option></select></p>
        </div>
        <button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button>
        <a class="btn ghost" href="{{ route('admin.contacts.index') }}">Limpar</a>
        @can('contacts.export')<a class="btn secondary" href="{{ route('admin.contacts.export', request()->query()) }}"><x-icon name="download" size="16" />Exportar CSV</a>@endcan
        @can('contacts.create')<a class="btn" href="{{ route('admin.contacts.create') }}"><x-icon name="plus" size="16" />Novo contato</a>@endcan
        {{-- A importação saiu do menu lateral e passou a viver aqui, junto do
             resto do que se faz com contatos. Sem este botão ela ficaria sem
             nenhum caminho de acesso. --}}
        @can('contacts.import')<a class="btn secondary" href="{{ route('admin.contacts.import') }}"><x-icon name="upload" size="16" />Importar</a>@endcan
    </form>
    <section class="card table-wrap">
        @if($contacts->isEmpty())
            <p class="muted">Nenhum contato encontrado.</p>
        @else
            <table>
                <thead><tr><th><input type="checkbox" title="Selecionar página"></th><th>Nome</th><th>Telefone</th><th>E-mail</th><th>Cidade</th><th>Estado</th><th>Etiquetas</th><th>Status</th><th>Autorização</th><th>Não contatar</th><th>Último contato</th><th>Cadastro</th><th>Ações</th></tr></thead>
                <tbody>
                @foreach($contacts as $contact)
                    <tr>
                        <td><input form="bulk-action" type="checkbox" name="ids[]" value="{{ $contact->id }}"></td>
                        <td>{{ $contact->name }}<br><span class="muted">{{ $contact->first_name }}</span></td>
                        <td>{{ $contact->phone }}<br><span class="muted">{{ $contact->phone_normalized }}</span></td>
                        <td>{{ $contact->email }}</td>
                        <td>{{ $contact->city }}</td>
                        <td>{{ $contact->state }}</td>
                        <td>@foreach($contact->tags as $tag)<span style="display:inline-block;color:#fff;background:{{ $tag->color }};border-radius:6px;padding:2px 6px;margin:1px;">{{ $tag->name }}</span>@endforeach</td>
                        <td>{{ $contact->status->label() }}</td>
                        <td>{{ $contact->consent_status->label() }}</td>
                        <td>@if($contact->do_not_contact)<strong style="color:var(--danger);">Não contatar</strong>@else Não @endif</td>
                        <td>{{ $contact->last_contacted_at?->format($dateTimeFormat) ?? '-' }}</td>
                        <td>{{ $contact->created_at->format($dateFormat) }}</td>
                        <td class="actions"><a class="btn ghost" href="{{ route('admin.contacts.show', $contact) }}">Ver</a>@can('contacts.update')<a class="btn ghost" href="{{ route('admin.contacts.edit', $contact) }}">Editar</a>@endcan</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $contacts->links() }}
        @endif
    </section>
    <form id="bulk-action" method="post" class="card" style="margin-top:16px;" onsubmit="return confirm('Confirma a ação em massa para os contatos selecionados ou filtrados?')">
        @csrf
        <h2>Ações em massa</h2>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;">
            <input type="checkbox" name="all_filtered" value="1" style="width:auto;min-height:auto;">
            Selecionar todos os resultados filtrados
        </label>
        @foreach($filters as $key => $value)
            @if(is_scalar($value))
                <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
            @endif
        @endforeach
        <div class="grid grid-3">
            @can('contacts.manage_tags')
                <p><label>Etiqueta</label><select name="tag_id"><option value="">Selecione</option>@foreach($tags as $tag)<option value="{{ $tag->id }}">{{ $tag->name }}</option>@endforeach</select></p>
                <p><label>Ação da etiqueta</label><select name="mode"><option value="add">Adicionar</option><option value="remove">Remover</option></select></p>
            @endcan
            @can('contacts.update')
                <p><label>Status</label><select name="status"><option value="">Selecione</option>@foreach($statuses as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</select></p>
            @endcan
            @can('contacts.mark_do_not_contact')
                <p><label>Motivo para não contatar</label><input name="do_not_contact_reason"></p>
            @endcan
        </div>
        <div class="actions">
            @can('contacts.manage_tags')<button class="btn" formaction="{{ route('admin.contacts.bulk.tags') }}" type="submit">Aplicar etiqueta</button>@endcan
            @can('contacts.update')<button class="btn secondary" formaction="{{ route('admin.contacts.bulk.status') }}" type="submit">Alterar status</button>@endcan
            @can('contacts.mark_do_not_contact')<button class="btn danger" formaction="{{ route('admin.contacts.bulk.do-not-contact') }}" name="do_not_contact" value="1" type="submit">Marcar não contatar</button><button class="btn ghost" formaction="{{ route('admin.contacts.bulk.do-not-contact') }}" name="do_not_contact" value="0" type="submit">Desmarcar não contatar</button>@endcan
            @can('contacts.delete')<button class="btn danger" formaction="{{ route('admin.contacts.bulk.destroy') }}" name="_method" value="delete" type="submit">Excluir selecionados</button>@endcan
        </div>
        <p class="muted">Antes de confirmar, revise a quantidade de contatos selecionados ou marque todos os resultados filtrados. A ação respeita as permissões do usuário.</p>
    </form>
</x-layouts.app>
