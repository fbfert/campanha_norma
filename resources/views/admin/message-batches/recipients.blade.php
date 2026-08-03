<x-layouts.app title="Destinatários do lote" breadcrumbs="Inicio / Mensagens / Lotes / Destinatarios">
    <section class="card">
        <form method="get" class="grid grid-3">
            <div><label for="q">Nome</label><input id="q" name="q" value="{{ request('q') }}"></div>
            <div><label for="eligibility_status">Aptidão</label><select id="eligibility_status" name="eligibility_status"><option value="">Todos</option><option value="eligible" @selected(request('eligibility_status') === 'eligible')>Apto</option><option value="excluded" @selected(request('eligibility_status') === 'excluded')>Excluído</option></select></div>
            <div class="actions"><button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button><a class="btn ghost" href="{{ route('admin.message-batches.recipients', $messageBatch) }}">Limpar</a></div>
        </form>
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="table-wrap"><table><thead><tr><th>Posição</th><th>Nome</th><th>Telefone</th><th>Cidade</th>@if($messageBatch->is_campaign)<th>Modelo sorteado</th>@endif<th>Status</th><th>Motivo</th><th>Mensagem</th></tr></thead><tbody>@foreach($recipients as $recipient)<tr><td>{{ $recipient->random_position }}</td><td>{{ $recipient->contact_name_snapshot }}@if($recipient->contact_id) <a class="btn ghost" href="{{ route('admin.contacts.edit', $recipient->contact_id) }}" target="_blank" rel="noopener" title="Abrir o cadastro em outra aba">Editar contato</a>@endif</td><td>{{ $recipient->contact_phone_snapshot }}</td><td>{{ $recipient->contact_city_snapshot }}</td>@if($messageBatch->is_campaign)<td>{{ $recipient->message_template_name_snapshot ?? '-' }}</td>@endif<td>{{ $recipient->eligibility_status->label() }}</td><td>{{ $recipient->ineligibility_reason }}</td><td><pre style="white-space:pre-wrap;">{{ $recipient->rendered_message }}</pre></td></tr>@endforeach</tbody></table></div>
        {{ $recipients->links() }}
    </section>
</x-layouts.app>
