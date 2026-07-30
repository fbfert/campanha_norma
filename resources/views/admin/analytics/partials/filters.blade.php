{{-- Filtros preservados na URL: a tela toda e compartilhável por link. --}}
<form method="get" class="card">
    <div>
        <label for="from">De</label>
        <input id="from" name="from" type="date" value="{{ $from->toDateString() }}">
    </div>
    <div>
        <label for="to">Até</label>
        <input id="to" name="to" type="date" value="{{ $to->toDateString() }}">
    </div>
    <div>
        <label for="flow">Fluxo</label>
        <select id="flow" name="flow">
            <option value="">Todos</option>
            @foreach($flows as $flow)
                <option value="{{ $flow->id }}" @selected($flowId === $flow->id)>{{ $flow->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="actions">
        <button class="btn" type="submit">Aplicar</button>
    </div>
</form>
