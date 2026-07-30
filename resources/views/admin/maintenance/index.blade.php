<x-layouts.app title="Manutenção" breadcrumbs="Operacao / Manutencao">
    <section class="card"><h2>Ações de manutenção</h2><p class="muted">Ações exigem confirmação e registram auditoria.</p><div class="grid grid-3">
        @can('maintenance.sync_counters')<form method="post" action="{{ route('admin.maintenance.sync-counters') }}">@csrf<label class="checkbox"><input type="checkbox" name="confirm" value="1" required> Confirmo a sincronização</label><button class="btn" type="submit">Sincronizar contadores</button></form>@endcan
        @can('maintenance.view')<form method="post" action="{{ route('admin.maintenance.find-inconsistencies') }}">@csrf<button class="btn secondary" type="submit">Verificar inconsistências</button></form>@endcan
        @can('maintenance.recover_stuck')<form method="post" action="{{ route('admin.maintenance.recover-stuck') }}">@csrf<label class="checkbox"><input type="checkbox" name="confirm" value="1" required> Confirmo a recuperação</label><button class="btn secondary" type="submit">Recuperar presas</button></form>@endcan
        @can('maintenance.cleanup_logs')<form method="post" action="{{ route('admin.maintenance.cleanup') }}">@csrf<label class="checkbox"><input type="checkbox" name="confirm" value="1" required> Confirmo a limpeza</label><button class="btn secondary" type="submit">Limpar expirados</button></form>@endcan
        @can('maintenance.apply_retention')<form method="post" action="{{ route('admin.maintenance.apply-retention') }}">@csrf<label class="checkbox"><input type="checkbox" name="confirm" value="1" required> Confirmo a retenção</label><button class="btn danger" type="submit">Aplicar retenção</button></form>@endcan
    </div></section>
    <section class="card" style="margin-top:16px;"><h2>Resumo diagnostico</h2><ul class="stack-list">@foreach($diagnostics as $name => $item)<li>{{ $name }}: {{ $item['status']->label() }} - {{ $item['message'] }}</li>@endforeach</ul></section>
</x-layouts.app>
