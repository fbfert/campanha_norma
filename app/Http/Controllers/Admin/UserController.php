<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('view-users'), 403);

        $filters = $request->only(['name', 'email', 'role', 'status']);

        $users = User::query()
            ->with('roles')
            ->when($filters['name'] ?? null, fn ($query, $value) => $query->where('name', 'like', "%{$value}%"))
            ->when($filters['email'] ?? null, fn ($query, $value) => $query->where('email', 'like', "%{$value}%"))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['role'] ?? null, fn ($query, $value) => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('slug', $value)))
            ->orderBy('name')
            ->paginate((int) config('app.records_per_page', 20))
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->get(),
            'statuses' => UserStatus::cases(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('manage-users'), 403);

        return view('admin.users.create', [
            'roles' => Role::query()->orderBy('name')->get(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    public function store(UserStoreRequest $request, UserService $service): RedirectResponse
    {
        $user = $service->create($request->validated());

        return redirect()->route('admin.users.show', $user)->with('success', 'Usuário criado com sucesso.');
    }

    public function show(Request $request, User $user): View
    {
        abort_unless($request->user()->can('view-users'), 403);

        return view('admin.users.show', ['user' => $user->load('roles')]);
    }

    public function edit(Request $request, User $user): View
    {
        abort_unless($request->user()->can('manage-users'), 403);

        return view('admin.users.edit', [
            'user' => $user->load('roles'),
            'roles' => Role::query()->orderBy('name')->get(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    public function update(UserUpdateRequest $request, User $user, UserService $service): RedirectResponse
    {
        $service->update($user, $request->validated());

        return redirect()->route('admin.users.show', $user)->with('success', 'Usuário atualizado com sucesso.');
    }

    public function status(Request $request, User $user, UserService $service): RedirectResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);

        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
        ]);

        $service->changeStatus($request->user(), $user, UserStatus::from($data['status']));

        return back()->with('success', 'Status atualizado com sucesso.');
    }

    public function resetPassword(Request $request, User $user, UserService $service): RedirectResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);

        $password = $service->resetPassword($user);

        return back()
            ->with('success', 'Senha temporária gerada. Copie agora, ela não será exibida novamente.')
            ->with('temporary_password', $password);
    }

    public function destroy(Request $request, User $user, UserService $service): RedirectResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);

        $service->delete($request->user(), $user);

        return redirect()->route('admin.users.index')->with('success', 'Usuário excluído logicamente.');
    }
}
