<x-layouts.app title="Geografia" breadcrumbs="Inicio / Relatorios / Geografia">
    @include('admin.analytics.partials.filters')

    <section class="card">
        <p class="muted">
            A localidade vem de duas fontes: o cadastro do contato e o que a propria pessoa declarou na resposta. Nada e
            deduzido de DDD — o DDD diz onde a linha foi habilitada, nao onde a pessoa mora.
        </p>
        <p class="muted">Nao existe filtro cruzando geografia com atributo sensivel. Nao esta desligado: nao foi construido.</p>
    </section>

    <section class="card">
        <h2>Localidade declarada pela pessoa</h2>
        @if($declared === [])
            @include('admin.analytics.partials.empty')
        @else
            <table>
                <thead><tr><th>Localidade</th><th>Regiao</th><th>Respostas</th></tr></thead>
                <tbody>@foreach($declared as $row)
                    <tr><td>{{ $row['locality'] }}</td><td>{{ $row['region'] ?? '—' }}</td>
                        <td>{{ $row['suppressed'] ? 'suprimido' : $row['total'] }}</td></tr>
                @endforeach</tbody>
            </table>
            @include('admin.analytics.partials.suppression')
        @endif
    </section>

    <section class="card">
        <h2>Cidade do cadastro</h2>
        @if($registered === [])
            @include('admin.analytics.partials.empty')
        @else
            <table>
                <thead><tr><th>Cidade</th><th>Estado</th><th>Respostas</th></tr></thead>
                <tbody>@foreach($registered as $row)
                    <tr><td>{{ $row['city'] }}</td><td>{{ $row['state'] ?? '—' }}</td>
                        <td>{{ $row['suppressed'] ? 'suprimido' : $row['total'] }}</td></tr>
                @endforeach</tbody>
            </table>
        @endif
        <p class="muted">Respostas sem nenhuma origem geografica conhecida: <strong>{{ $withoutLocality }}</strong>. Sem esse numero, um mapa com poucas cidades parece completo.</p>
    </section>
</x-layouts.app>
