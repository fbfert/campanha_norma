<x-layouts.app title="Conexão WhatsApp" breadcrumbs="Inicio / WhatsApp / Conexao">
    @php($qr = session('whatsapp_qr'))
    @if($statusError)
        <div class="alert error">{{ $statusError }}</div>
    @endif

    <div class="grid grid-2">
        <section class="card">
            <h2>Status da conexão</h2>
            <p><strong>Estado:</strong> <span style="color: {{ $connection->status->color() }}">{{ $connection->status->label() }}</span></p>
            <p><strong>Provedor:</strong> {{ $connection->provider }}</p>
            <p><strong>Número conectado:</strong> {{ $connection->phone_number ?? 'Não informado' }}</p>
            <p><strong>Nome da conta:</strong> {{ $connection->display_name ?? 'Não informado' }}</p>
            <p><strong>Conectado em:</strong> {{ $connection->connected_at?->format($dateTimeFormat) ?? 'Sem registro' }}</p>
            <p><strong>Última atividade:</strong> {{ $connection->last_activity_at?->format($dateTimeFormat) ?? 'Sem registro' }}</p>
            <p><strong>Última consulta:</strong> {{ $connection->last_status_check_at?->format($dateTimeFormat) ?? 'Sem registro' }}</p>
            <p><strong>Último QR:</strong> {{ $connection->last_qr_generated_at?->format($dateTimeFormat) ?? 'Sem registro' }}</p>
            <p><strong>Último erro:</strong> {{ $connection->last_error_message ?? 'Sem erro registrado' }}</p>
        </section>

        <section class="card">
            <h2>Ações</h2>
            <div class="actions">
                <form method="post" action="{{ route('admin.whatsapp.status') }}">@csrf <button class="btn ghost" type="submit">Verificar status</button></form>
                @can('whatsapp.connection.manage')
                    <form method="post" action="{{ route('admin.whatsapp.connect') }}">@csrf <button class="btn" type="submit">Inicializar serviço</button></form>
                    <form method="post" action="{{ route('admin.whatsapp.qrcode') }}">@csrf <button class="btn secondary" type="submit">Gerar QR Code</button></form>
                    <form method="post" action="{{ route('admin.whatsapp.reconnect') }}">@csrf <button class="btn secondary" type="submit">Reconectar</button></form>
                @endcan
                @can('whatsapp.connection.disconnect')
                    <form method="post" action="{{ route('admin.whatsapp.disconnect') }}" onsubmit="return confirm('Esta ação encerrará a conexão atual. A sessão salva poderá permanecer disponível para reconexão.')">@csrf <button class="btn danger" type="submit">Desconectar</button></form>
                @endcan
            </div>

            @can('whatsapp.connection.clear_session')
                <hr>
                <form method="post" action="{{ route('admin.whatsapp.session.clear') }}" onsubmit="return confirm('Esta ação apagará a autenticação salva. Será necessário ler um novo QR Code.')">
                    @csrf
                    @method('delete')
                    <label for="current_password">Senha atual</label>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password">
                    <label for="confirmation">Confirmação</label>
                    <input id="confirmation" name="confirmation" placeholder="EXCLUIR SESSÃO">
                    <button class="btn danger" type="submit" style="margin-top:10px;">Excluir sessão</button>
                </form>
            @endcan
        </section>
    </div>

    <section class="card" style="margin-top:16px;">
        <h2>QR Code</h2>
        @if($qr && filled($qr['qr_code'] ?? null))
            <div style="text-align:center;">
                <img src="{{ $qr['qr_code'] }}" alt="QR Code para conexão do WhatsApp" style="max-width:320px;width:100%;height:auto;">
                <p class="muted">Gerado em {{ $qr['generated_at'] ?? 'agora' }}. Expira em {{ $qr['expires_at'] ?? 'breve' }}.</p>
            </div>
        @else
            <p class="muted">Nenhum QR Code disponível. Gere um novo QR Code quando o serviço estiver pronto.</p>
        @endif
        <div class="alert error">Exiba o QR Code somente para administradores autorizados. Ele não deve ser enviado por e-mail ou salvo em locais públicos.</div>
    </section>

    @can('whatsapp.test_message.send')
        <section class="card" style="margin-top:16px;">
            <h2>Mensagem individual de teste</h2>
            <form method="post" action="{{ route('admin.whatsapp.test-message') }}" onsubmit="return confirm('Enviar uma unica mensagem de teste para o contato selecionado?')">
                @csrf
                <div class="grid grid-2">
                    <div>
                        <label for="contact_id">Contato</label>
                        <select id="contact_id" name="contact_id" required>
                            <option value="">Selecione</option>
                            @foreach($contacts as $contact)
                                <option value="{{ $contact->id }}">{{ $contact->name }} - {{ $contact->phone }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="message">Mensagem</label>
                        <textarea id="message" name="message" rows="3" maxlength="1000" required>Ola, esta e uma mensagem de teste do sistema.</textarea>
                    </div>
                </div>
                <button class="btn" type="submit" style="margin-top:10px;">Enviar mensagem de teste</button>
            </form>
        </section>
    @endcan

    <section class="card" style="margin-top:16px;">
        <div class="actions" style="justify-content:space-between;">
            <h2>Eventos recentes</h2>
            @can('whatsapp.events.view')
                <a class="btn ghost" href="{{ route('admin.whatsapp.events') }}">Ver todos</a>
            @endcan
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Evento</th><th>Status</th><th>Descrição</th><th>Erro</th></tr></thead>
                <tbody>
                    @forelse($connection->events->take(8) as $event)
                        <tr>
                            <td>{{ $event->created_at?->format($dateTimeFormat) }}</td>
                            <td>{{ $event->event_type }}</td>
                            <td>{{ $event->status?->label() ?? '-' }}</td>
                            <td>{{ $event->description }}</td>
                            <td>{{ $event->error_code ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Nenhum evento registrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
