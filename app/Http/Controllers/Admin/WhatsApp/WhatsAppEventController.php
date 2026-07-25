<?php

namespace App\Http\Controllers\Admin\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppConnectionEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhatsAppEventController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('whatsapp.events.view'), 403);

        $events = WhatsAppConnectionEvent::query()
            ->with('user')
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('error_code'), fn ($query) => $query->where('error_code', $request->string('error_code')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('date_to')))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.whatsapp.events', [
            'events' => $events,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
