@php
    $isNew = ! $base->exists;
@endphp
<x-layouts.app
    :title="$isNew ? 'Nova base de conhecimento' : 'Editar base de conhecimento'"
    :breadcrumbs="$isNew ? 'Inicio / Base de conhecimento / Nova base' : 'Inicio / Base de conhecimento / Editar base'">

    @if($errors->any())
        <div class="alert error">
            <ul>
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section class="card">
        <form method="post" action="{{ $isNew ? route('admin.knowledge.bases.store') : route('admin.knowledge.bases.update', $base) }}">
            @csrf
            @unless($isNew) @method('put') @endunless

            <div>
                <label for="name">Nome</label>
                <input id="name" name="name" type="text" maxlength="255" required value="{{ old('name', $base->name) }}">
            </div>

            <div>
                <label for="description">Descrição</label>
                <textarea id="description" name="description" rows="2" maxlength="1000">{{ old('description', $base->description) }}</textarea>
            </div>

            <div>
                <label for="purpose">Finalidade</label>
                <textarea id="purpose" name="purpose" rows="2" maxlength="1000">{{ old('purpose', $base->purpose) }}</textarea>
            </div>

            <div>
                <label for="usage_policy">Política de uso</label>
                <textarea id="usage_policy" name="usage_policy" rows="3" maxlength="2000">{{ old('usage_policy', $base->usage_policy) }}</textarea>
                <p class="muted">Registre aqui o que esta base pode e não pode sustentar. E o texto que a equipe consulta antes de aprovar um documento.</p>
            </div>

            <fieldset>
                <legend>Fluxos que podem consultar esta base</legend>
                <p class="muted">A base e opt-in por fluxo. Um fluxo sem base associada não produz nenhuma recuperação.</p>
                @foreach($flows as $flow)
                    <label>
                        <input type="checkbox" name="flow_ids[]" value="{{ $flow->id }}"
                            @checked(in_array($flow->id, old('flow_ids', $selectedFlows), false))>
                        {{ $flow->name }}
                    </label>
                @endforeach
            </fieldset>

            <div class="actions">
                <button class="btn" type="submit">Salvar</button>
                <a class="btn ghost" href="{{ route('admin.knowledge.bases.index') }}">Cancelar</a>
            </div>
        </form>
    </section>
</x-layouts.app>
