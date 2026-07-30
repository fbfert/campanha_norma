<x-layouts.app title="Iniciar conversa" breadcrumbs="Atendimento / Conversas / Iniciar conversa">
    <section class="card">
        <div class="actions" style="justify-content:space-between;align-items:flex-start;">
            <div>
                <h2 style="margin-top:0;">Com quem voce quer falar?</h2>
                <p class="muted">
                    Escolher um contato abre a conversa. Nada e enviado agora &mdash; a primeira
                    mensagem e escrita na tela seguinte.
                </p>
            </div>
            <div class="actions">
                <a class="btn ghost" href="{{ route('admin.conversations.index') }}"><x-icon name="reply" size="16" />Voltar as conversas</a>
                @can('contacts.create')
                    <a class="btn secondary" href="{{ route('admin.contacts.create') }}"><x-icon name="plus" size="16" />Novo contato</a>
                @endcan
            </div>
        </div>

        <form method="get" class="grid grid-3" style="margin-top:16px;">
            <p>
                <label for="q">Busca</label>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nome, telefone ou e-mail" autofocus>
            </p>
            <p>
                <label for="tag_id">Etiqueta</label>
                <select id="tag_id" name="tag_id">
                    <option value="">Todas</option>
                    @foreach($tags as $tag)
                        <option value="{{ $tag->id }}" @selected(($filters['tag_id'] ?? '') == $tag->id)>{{ $tag->name }}</option>
                    @endforeach
                </select>
            </p>
            <p>
                <label for="status">Status do contato</label>
                <select id="status" name="status">
                    <option value="">Todos</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </p>
            <p>
                <label for="city">Cidade</label>
                <input id="city" name="city" value="{{ $filters['city'] ?? '' }}">
            </p>
            <p>
                <label for="state">Estado</label>
                <input id="state" name="state" value="{{ $filters['state'] ?? '' }}">
            </p>
            <p>
                <label for="only_eligible">Quem pode ser contatado</label>
                <select id="only_eligible" name="only_eligible">
                    <option value="1" @selected(request()->boolean('only_eligible', true))>Somente quem pode receber</option>
                    <option value="0" @selected(request()->has('only_eligible') && ! request()->boolean('only_eligible'))>Mostrar todos, com o motivo</option>
                </select>
            </p>
            <div class="actions" style="grid-column:1/-1;">
                <button class="btn" type="submit"><x-icon name="search" size="16" />Buscar</button>
                <a class="btn ghost" href="{{ route('admin.conversations.create') }}">Limpar</a>
            </div>
        </form>
    </section>

    @error('contact_id')
        <p class="alert error"><x-icon name="alert" size="18" />{{ $message }}</p>
    @enderror

    <section class="card table-wrap">
        @if($contacts->isEmpty())
            <div class="empty-state">
                <x-icon name="empty" size="40" />
                <p>Nenhum contato encontrado com esses filtros.</p>
                @can('contacts.create')
                    <a class="btn secondary" href="{{ route('admin.contacts.create') }}"><x-icon name="plus" size="16" />Cadastrar um contato</a>
                @endcan
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Telefone</th>
                        <th>Cidade</th>
                        <th>Etiquetas</th>
                        <th>Situacao</th>
                        <th>Ultimo contato</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($contacts as $contact)
                    @php
                        // Um contato so pode receber conversa se estiver ativo,
                        // nao marcado como nao contatar e com telefone valido.
                        // O motivo aparece na propria linha: se o botao some sem
                        // explicacao, quem esta operando acha que e defeito.
                        $impedimento = match (true) {
                            $contact->do_not_contact => 'Marcado como nao contatar',
                            $contact->status !== \App\Enums\ContactStatus::Active => 'Contato '.strtolower($contact->status->label()),
                            blank($contact->phone_normalized) => 'Sem telefone valido',
                            default => null,
                        };
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $contact->name }}</strong>
                            @if($contact->email)<br><span class="muted">{{ $contact->email }}</span>@endif
                        </td>
                        <td>{{ $contact->phone ?: '-' }}</td>
                        <td>{{ $contact->city }}{{ $contact->state ? ' / '.$contact->state : '' }}</td>
                        <td>
                            @foreach($contact->tags as $tag)
                                <span style="display:inline-block;color:#fff;background:{{ $tag->color }};border-radius:6px;padding:2px 6px;margin:1px;">{{ $tag->name }}</span>
                            @endforeach
                        </td>
                        <td>
                            @if($impedimento)
                                <span class="muted">{{ $impedimento }}</span>
                            @else
                                {{ $contact->status->label() }}
                            @endif
                        </td>
                        <td>{{ $contact->last_contacted_at?->format($dateTimeFormat) ?? '-' }}</td>
                        <td class="actions">
                            @if($impedimento)
                                <button class="btn secondary" type="button" disabled title="{{ $impedimento }}">Nao disponivel</button>
                            @else
                                <form method="post" action="{{ route('admin.conversations.store') }}">
                                    @csrf
                                    <input type="hidden" name="contact_id" value="{{ $contact->id }}">
                                    <button class="btn" type="submit"><x-icon name="chat" size="16" />Iniciar conversa</button>
                                </form>
                            @endif
                            <a class="btn ghost" href="{{ route('admin.contacts.show', $contact) }}">Ver</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $contacts->links() }}
        @endif
    </section>
</x-layouts.app>
