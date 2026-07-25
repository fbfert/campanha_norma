<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('view-audit'), 403);

        $filters = $request->only(['user_id', 'action', 'entity_type', 'date_from', 'date_to', 'ip_address']);

        $auditLogs = AuditLog::query()
            ->with('user')
            ->when($filters['user_id'] ?? null, fn ($query, $value) => $query->where('user_id', $value))
            ->when($filters['action'] ?? null, fn ($query, $value) => $query->where('action', 'like', "%{$value}%"))
            ->when($filters['entity_type'] ?? null, fn ($query, $value) => $query->where('entity_type', 'like', "%{$value}%"))
            ->when($filters['ip_address'] ?? null, fn ($query, $value) => $query->where('ip_address', 'like', "%{$value}%"))
            ->when($filters['date_from'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '<=', $value))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'auditLogs' => $auditLogs,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): View
    {
        abort_unless($request->user()->can('view-audit'), 403);

        return view('admin.audit-logs.show', ['auditLog' => $auditLog->load('user')]);
    }
}
