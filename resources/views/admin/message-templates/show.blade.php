<x-layouts.app title="Modelo" breadcrumbs="Inicio / Mensagens / Modelos / Detalhes">
    <section class="card">
        <div class="actions" style="justify-content:space-between;">
            <h2>{{ $template->name }}</h2>
            <div class="actions">@can('message_templates.update')<a class="btn" href="{{ route('admin.message-templates.edit', $template) }}">Editar</a>@endcan @can('message_templates.duplicate')<form method="post" action="{{ route('admin.message-templates.duplicate', $template) }}">@csrf <button class="btn secondary" type="submit">Duplicar</button></form>@endcan @can('message_templates.delete')<form method="post" action="{{ route('admin.message-templates.destroy', $template) }}" onsubmit="return confirm('Excluir este modelo logicamente?')">@csrf @method('delete')<button class="btn danger" type="submit">Excluir</button></form>@endcan</div>
        </div>
        <p><strong>Status:</strong> {{ $template->status->label() }} | <strong>Versao:</strong> {{ $template->version }}</p>
        <p>{{ $template->description }}</p>
        <pre style="white-space:pre-wrap;">{{ $template->body }}</pre>
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Pre-visualizacao</h2>
        <form method="post" action="{{ route('admin.message-templates.preview') }}">
            @csrf
            <input type="hidden" name="body" value="{{ $template->body }}">
            <label for="contact_id">Contato</label>
            <select id="contact_id" name="contact_id">@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}</option>@endforeach</select>
            <button class="btn" type="submit" style="margin-top:10px;">Gerar previa</button>
        </form>
    </section>
    <section class="card" style="margin-top:16px;">
        <h2>Versoes</h2>
        <div class="table-wrap"><table><thead><tr><th>Versao</th><th>Criada em</th><th>Usuario</th><th>Placeholders</th></tr></thead><tbody>@foreach($template->versions as $version)<tr><td>{{ $version->version }}</td><td>{{ $version->created_at?->format($dateTimeFormat) }}</td><td>{{ $version->creator?->name ?? '-' }}</td><td>{{ implode(', ', $version->placeholders ?? []) }}</td></tr>@endforeach</tbody></table></div>
    </section>
</x-layouts.app>
