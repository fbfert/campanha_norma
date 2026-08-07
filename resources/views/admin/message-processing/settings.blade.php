<x-layouts.app title="Configurações de envio" breadcrumbs="Mensagens / Configuracoes de envio">
    <div class="panel">
        <form method="post" action="{{ route('admin.message-settings.update') }}" class="form-grid">
            @csrf
            @method('put')
            <label>Máximo por minuto <input type="number" name="max_per_minute" value="{{ old('max_per_minute', $settings->max_per_minute) }}" min="1" required></label>
            <label>Máximo por hora <input type="number" name="max_per_hour" value="{{ old('max_per_hour', $settings->max_per_hour) }}" min="1" required></label>
            <label>Máximo por dia <input type="number" name="max_per_day" value="{{ old('max_per_day', $settings->max_per_day) }}" min="1" required></label>
            <label>Intervalo mínimo em segundos <input type="number" name="minimum_interval_seconds" value="{{ old('minimum_interval_seconds', $settings->minimum_interval_seconds) }}" min="0" required></label>
            <label>Horário inicial <input type="time" name="start_time" value="{{ old('start_time', substr($settings->start_time, 0, 5)) }}" required></label>
            <label>Horário final <input type="time" name="end_time" value="{{ old('end_time', substr($settings->end_time, 0, 5)) }}" required></label>
            <label>Fuso horário <input name="timezone" value="{{ old('timezone', $settings->timezone) }}" required></label>
            <label>Máximo de tentativas <input type="number" name="max_attempts" value="{{ old('max_attempts', $settings->max_attempts) }}" min="1" max="10" required></label>
            <label>Intervalo entre tentativas (minutos) <input type="number" name="retry_interval_minutes" value="{{ old('retry_interval_minutes', $settings->retry_interval_minutes) }}" min="1" required></label>
            <label>Backoff
                <select name="retry_backoff_type">
                    @foreach(['fixed' => 'Fixo', 'linear' => 'Linear', 'exponential' => 'Exponencial'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('retry_backoff_type', $settings->retry_backoff_type->value) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <fieldset class="full">
                <legend>Dias permitidos</legend>
                @foreach([1 => 'Segunda', 2 => 'Terca', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sabado', 7 => 'Domingo'] as $day => $label)
                    <label class="checkbox"><input type="checkbox" name="allowed_weekdays[]" value="{{ $day }}" @checked(in_array($day, old('allowed_weekdays', $settings->allowed_weekdays ?? [])))> {{ $label }}</label>
                @endforeach
            </fieldset>
            <label class="checkbox full"><input type="checkbox" name="pause_when_disconnected" value="1" @checked(old('pause_when_disconnected', $settings->pause_when_disconnected))> Pausar quando WhatsApp desconectar</label>

            <fieldset class="full">
                <legend>Trava de reciprocidade</legend>
                <p class="muted">
                    Os limites acima são de ritmo e olham só para o nosso lado: dá para abordar mil
                    pessoas em ritmo impecável sem que nenhuma responda, e nada nota. Esta trava mede a
                    conversa. Quando o número de pessoas abordadas que ainda não responderam alcança o
                    teto, o envio de lotes e campanhas para e espera &mdash; e o que destrava não é o
                    relógio, é alguém responder.
                </p>
                <label>
                    Parar depois de quantas pessoas sem resposta
                    <input type="number" name="unanswered_lock_threshold" value="{{ old('unanswered_lock_threshold', $settings->unanswered_lock_threshold) }}" min="0" max="10000" required>
                </label>
                <p class="muted">
                    A contagem é de pessoas, não de mensagens: quem recebeu três e não respondeu conta
                    uma vez. Assim que a pessoa escreve, ela sai da conta e o envio recomeça sozinho,
                    sem ninguém precisar liberar nada. <strong>Zero desliga a trava.</strong>
                    @isset($emSilencio)
                        <br>Agora: <strong>{{ $emSilencio }}</strong> pessoa(s) abordada(s) sem resposta.
                    @endisset
                </p>
            </fieldset>

            <div class="full actions"><button class="btn" type="submit">Salvar</button></div>
        </form>
    </div>
</x-layouts.app>
