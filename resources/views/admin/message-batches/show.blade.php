<x-layouts.app title="Lote" breadcrumbs="Inicio / Mensagens / Lotes / Detalhes">
    <section class="card">
        <div class="actions" style="justify-content:space-between;">
            <h2>{{ $batch->name }}</h2>
            <div class="actions">@can('message_batches.update')@if($batch->status->value === 'draft')<a class="btn" href="{{ route('admin.message-batches.edit', $batch) }}">Editar</a><form method="post" action="{{ route('admin.message-batches.randomize', $batch) }}">@csrf <button class="btn secondary" type="submit">Regerar ordem</button></form>@endif@endcan @can('message_batches.duplicate')<form method="post" action="{{ route('admin.message-batches.duplicate', $batch) }}">@csrf <button class="btn secondary" type="submit">Duplicar</button></form>@endcan</div>
        </div>
        <p><strong>Status:</strong> {{ $batch->status->label() }} | <strong>Tipo:</strong> {{ $batch->is_campaign ? 'Campanha' : $batch->selection_type->label() }}</p>
        <p><strong>Selecionados:</strong> {{ $batch->selection_total }} | <strong>Aptos:</strong> {{ $batch->eligible_total }} | <strong>Excluídos:</strong> {{ $batch->ineligible_total }}</p>
        @if($batch->is_campaign)
            <p><strong>Campanha:</strong> {{ count($batch->campaign_templates_snapshot ?? []) }} modelos selecionados. Cada destinatário recebeu um modelo sorteado e uma posição aleatória.</p>
            <div class="table-wrap"><table><thead><tr><th>Modelo</th><th>Versão</th></tr></thead><tbody>@foreach($batch->campaign_templates_snapshot ?? [] as $template)<tr><td>{{ $template['name'] ?? '-' }}</td><td>{{ $template['version'] ?? '-' }}</td></tr>@endforeach</tbody></table></div>
        @else
            <p><strong>Modelo:</strong> {{ $batch->template?->name ?? 'Mensagem avulsa' }} v{{ $batch->message_template_version ?? '-' }}</p>
            <pre style="white-space:pre-wrap;">{{ $batch->message_body_snapshot }}</pre>
        @endif
        @if($batch->conversation_flow_id)
            <p><strong>Resposta automática:</strong> fluxo "{{ $batch->conversationFlow?->name ?? ($batch->conversation_flow_snapshot['name'] ?? '-') }}" @if($batch->conversationFlow && ! $batch->conversationFlow->isRunnable())<span class="muted">(fluxo {{ strtolower($batch->conversationFlow->status->label()) }}: nenhuma resposta automática será enviada)</span>@endif</p>
        @else
            <p><strong>Resposta automática:</strong> nenhuma. As respostas deste lote vão para atendimento humano.</p>
        @endif
        @if($batch->status->value === 'ready')<div class="alert success">Este lote esta preparado e pode ser iniciado manualmente.</div>@endif
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Confirmação</h2>
        @if($batch->status->value === 'draft')
            <form method="post" action="{{ route('admin.message-batches.prepare', $batch) }}">@csrf <label for="confirmation">Confirmação explícita</label><input id="confirmation" name="confirmation" value="Confirmo a criação deste lote com os destinatários e mensagens apresentados."><button class="btn" type="submit" style="margin-top:10px;">Marcar como preparado</button></form>
        @endif
        @can('message_batches.cancel')@if(in_array($batch->status->value, ['draft', 'ready'], true))<form method="post" action="{{ route('admin.message-batches.cancel', $batch) }}" style="margin-top:12px;" onsubmit="return confirm('Cancelar este lote?')">@csrf <label for="cancel_reason">Motivo do cancelamento</label><input id="cancel_reason" name="cancel_reason"><button class="btn danger" type="submit" style="margin-top:10px;">Cancelar lote</button></form>@endif@endcan
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="actions" style="justify-content:space-between;"><h2>Destinatários</h2><div class="actions">@can('message_batches.view_recipients')<a class="btn ghost" href="{{ route('admin.message-batches.recipients', $batch) }}">Ver todos</a>@endcan @can('message_batches.export_preview')<a class="btn ghost" href="{{ route('admin.message-batches.ineligible.export', $batch) }}">Exportar previa</a>@endcan</div></div>
        <div class="table-wrap"><table><thead><tr><th>Posição</th><th>Nome</th><th>Telefone</th><th>Cidade</th>@if($batch->is_campaign)<th>Modelo sorteado</th>@endif<th>Aptidão</th><th>Motivo</th><th>Mensagem</th></tr></thead><tbody>@foreach($recipients as $recipient)<tr><td>{{ $recipient->random_position }}</td><td>{{ $recipient->contact_name_snapshot }}@if($recipient->contact_id) <a class="btn ghost" href="{{ route('admin.contacts.edit', $recipient->contact_id) }}" target="_blank" rel="noopener" title="Abrir o cadastro em outra aba">Editar contato</a>@endif</td><td>{{ $recipient->contact_phone_snapshot }}</td><td>{{ $recipient->contact_city_snapshot }}</td>@if($batch->is_campaign)<td>{{ $recipient->message_template_name_snapshot ?? '-' }}</td>@endif<td>{{ $recipient->eligibility_status->label() }}</td><td>{{ $recipient->ineligibility_reason }}</td><td><pre style="white-space:pre-wrap;">{{ $recipient->rendered_message }}</pre></td></tr>@endforeach</tbody></table></div>
        {{ $recipients->links() }}
    </section>
    <section class="card" style="margin-top:16px;">
        <div class="actions" style="justify-content:space-between;">
            <h2>Processamento e envio</h2>
            @can('message_processing.view')
                <a class="btn ghost" href="{{ route('admin.message-batches.processing', $batch) }}">Acompanhar processamento</a>
            @endcan
        </div>
        @if($batch->status->value === 'ready')
            <div class="actions">
                @can('message_processing.start')
                    <form method="post" action="{{ route('admin.message-batches.start', $batch) }}">@csrf <button class="btn" type="submit">Iniciar lote</button></form>
                @endcan
                @can('message_batches.update')
                    <form method="post" action="{{ route('admin.message-batches.revalidate', $batch) }}">@csrf <button class="btn secondary" type="submit">Atualizar lote</button></form>
                @endcan
            </div>
            <p class="muted">
                "Atualizar lote" reavalia os destinatários com o cadastro atual dos contatos. Quem estava
                inapto por dado faltando volta a ser apto assim que o cadastro for completado, sem precisar
                refazer o lote. Quem já recebeu não e tocado.
            </p>
        @else
            <p class="muted">Consulte o acompanhamento para status, tentativas e controles.</p>
        @endif
    </section>
    <section class="card" style="margin-top:16px;"><h2>Eventos</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Evento</th><th>Descrição</th><th>Usuário</th></tr></thead><tbody>@foreach($batch->events as $event)<tr><td>{{ $event->created_at?->format($dateTimeFormat) }}</td><td>{{ $event->event_type }}</td><td>{{ $event->description }}</td><td>{{ $event->user?->name ?? '-' }}</td></tr>@endforeach</tbody></table></div></section>
</x-layouts.app>
