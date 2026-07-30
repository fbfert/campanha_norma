<x-layouts.app title="Lotes" breadcrumbs="Inicio / Mensagens / Lotes">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div><label for="q">Nome</label><input id="q" name="q" value="{{ request('q') }}"></div>
            <div><label for="status">Status</label><select id="status" name="status"><option value="">Todos</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
            <div><label for="message_template_id">Modelo</label><select id="message_template_id" name="message_template_id"><option value="">Todos</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected((string) request('message_template_id') === (string) $template->id)>{{ $template->name }}</option>@endforeach</select></div>
            <div><label for="is_campaign">Tipo</label><select id="is_campaign" name="is_campaign"><option value="">Todos</option><option value="0" @selected(request('is_campaign') === '0')>Lote simples</option><option value="1" @selected(request('is_campaign') === '1')>Campanha</option></select></div>
            <div class="actions"><button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button><a class="btn ghost" href="{{ route('admin.message-batches.index') }}">Limpar</a>@can('message_batches.create')<a class="btn" href="{{ route('admin.message-batches.create') }}"><x-icon name="plus" size="16" />Novo lote</a><a class="btn secondary" href="{{ route('admin.campaigns.create') }}"><x-icon name="megaphone" size="16" />Campanha</a>@endcan</div>
        </form>
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>Mensagem</th><th>Tipo</th><th>Selecionados</th><th>Aptos</th><th>Excluídos</th><th>Status</th><th>Criador</th><th>Ações</th></tr></thead>
                <tbody>
                    @forelse($batches as $batch)
                        <tr><td>{{ $batch->name }}</td><td>{{ $batch->is_campaign ? 'Campanha com '.count($batch->campaign_templates_snapshot ?? []).' modelos' : (($batch->template?->name ?? 'Mensagem avulsa').' v'.($batch->message_template_version ?? '-')) }}</td><td>{{ $batch->is_campaign ? 'Campanha' : $batch->selection_type->label() }}</td><td>{{ $batch->selection_total }}</td><td>{{ $batch->eligible_total }}</td><td>{{ $batch->ineligible_total }}</td><td>{{ $batch->status->label() }}</td><td>{{ $batch->creator?->name ?? '-' }}</td><td class="actions"><a class="btn ghost" href="{{ route('admin.message-batches.show', $batch) }}">Ver</a>@can('message_processing.view')<a class="btn ghost" href="{{ route('admin.message-batches.processing', $batch) }}">Processamento</a>@endcan @can('message_batches.update')@if($batch->status->value === 'draft')<a class="btn ghost" href="{{ route('admin.message-batches.edit', $batch) }}">Editar</a>@endif@endcan</td></tr>
                    @empty
                        <tr><td colspan="9">Nenhum lote encontrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $batches->links() }}
    </section>
</x-layouts.app>
