<x-layouts.app title="Contato" breadcrumbs="Inicio / Contatos / Detalhes">
    @if($contact->do_not_contact)<div class="alert error">Este contato está marcado como não contatar. Motivo: {{ $contact->do_not_contact_reason }}</div>@endif
    <section class="card">
        <h2>{{ $contact->name }}</h2>
        <div class="grid grid-2">
            <p><strong>Primeiro nome:</strong> {{ $contact->first_name }}</p>
            <p><strong>Telefone:</strong> {{ $contact->phone }} / {{ $contact->phone_normalized }}</p>
            <p><strong>E-mail:</strong> {{ $contact->email }}</p>
            <p><strong>Cidade/UF:</strong> {{ $contact->city }} {{ $contact->state }}</p>
            <p><strong>Origem:</strong> {{ $contact->source->label() }}</p>
            <p><strong>Situação:</strong> {{ $contact->status->label() }}</p>
            <p><strong>Consentimento:</strong> {{ $contact->consent_status->label() }}</p>
            <p><strong>Criador:</strong> {{ $contact->creator?->name ?? '-' }}</p>
            <p><strong>Última alteração por:</strong> {{ $contact->updater?->name ?? '-' }}</p>
            <p><strong>Criado em:</strong> {{ $contact->created_at->format($dateTimeFormat) }}</p>
            <p><strong>Atualizado em:</strong> {{ $contact->updated_at->format($dateTimeFormat) }}</p>
            <p><strong>Excluído:</strong> {{ $contact->trashed() ? 'Sim' : 'Não' }}</p>
        </div>
        <p><strong>Etiquetas:</strong> @foreach($contact->tags as $tag)<span style="color:#fff;background:{{ $tag->color }};border-radius:6px;padding:2px 6px;margin:1px;">{{ $tag->name }}</span>@endforeach</p>
        <p><strong>Observações:</strong><br>{{ $contact->notes }}</p>
        <div class="actions">
            <a class="btn ghost" href="{{ route('admin.contacts.index') }}">Voltar</a>
            @can('contacts.update')<a class="btn" href="{{ route('admin.contacts.edit', $contact) }}">Editar</a>@endcan
            @can('contacts.delete')<form method="post" action="{{ route('admin.contacts.destroy', $contact) }}" onsubmit="return confirm('O contato será removido das listagens normais. O histórico será preservado. Esta ação não elimina imediatamente os dados.')">@csrf @method('delete')<button class="btn danger" type="submit">Excluir</button></form>@endcan
        </div>
    </section>
    @include('admin.contacts.history', ['history' => $contact->history])
    <div class="grid grid-2" style="margin-top:16px;">
        @can('histories.view')
            <section class="card"><strong>Historico de mensagens</strong><p>Envios consolidados deste contato.</p><a class="btn ghost" href="{{ route('admin.contacts.message-history', $contact) }}">Abrir historico</a></section>
        @endcan
        @foreach(['Respostas', 'Ultimo envio recebido'] as $future)
            <section class="card"><strong>{{ $future }}</strong><p class="muted">Modulo ainda nao implementado</p></section>
        @endforeach
    </div>
</x-layouts.app>
