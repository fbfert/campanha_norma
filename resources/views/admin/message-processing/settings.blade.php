<x-layouts.app title="Configuracoes de envio" breadcrumbs="Mensagens / Configuracoes de envio">
    <div class="panel">
        <form method="post" action="{{ route('admin.message-settings.update') }}" class="form-grid">
            @csrf
            @method('put')
            <label>Maximo por minuto <input type="number" name="max_per_minute" value="{{ old('max_per_minute', $settings->max_per_minute) }}" min="1" required></label>
            <label>Maximo por hora <input type="number" name="max_per_hour" value="{{ old('max_per_hour', $settings->max_per_hour) }}" min="1" required></label>
            <label>Maximo por dia <input type="number" name="max_per_day" value="{{ old('max_per_day', $settings->max_per_day) }}" min="1" required></label>
            <label>Intervalo minimo em segundos <input type="number" name="minimum_interval_seconds" value="{{ old('minimum_interval_seconds', $settings->minimum_interval_seconds) }}" min="0" required></label>
            <label>Horario inicial <input type="time" name="start_time" value="{{ old('start_time', substr($settings->start_time, 0, 5)) }}" required></label>
            <label>Horario final <input type="time" name="end_time" value="{{ old('end_time', substr($settings->end_time, 0, 5)) }}" required></label>
            <label>Fuso horario <input name="timezone" value="{{ old('timezone', $settings->timezone) }}" required></label>
            <label>Maximo de tentativas <input type="number" name="max_attempts" value="{{ old('max_attempts', $settings->max_attempts) }}" min="1" max="10" required></label>
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
            <div class="full actions"><button class="btn" type="submit">Salvar</button></div>
        </form>
    </div>
</x-layouts.app>
