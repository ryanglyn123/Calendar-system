<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('admin/roles/Index', [
            'roles' => RoleResource::collection(
                Role::query()->with('permissions')->withCount('users')->orderBy('name')->get(),
            ),
            'permissions' => Permission::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = Role::create([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        $role->syncPermissions($request->validated('permissions', []));

        Toast::success("Role {$role->name} created.");

        return back();
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        if (! $role->is_system && $request->has('name')) {
            $role->update(['name' => $request->validated('name')]);
        }

        $role->update(['description' => $request->validated('description')]);

        $role->syncPermissions($request->validated('permissions', []));

        Toast::success("Role {$role->name} updated.");

        return back();
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        // Invariants that hold for every actor, super admins included.
        abort_if((bool) $role->is_system, 403, 'System roles cannot be deleted.');
        abort_if($role->users()->exists(), 403, 'Roles still assigned to users cannot be deleted.');

        $role->delete();

        Toast::success('Role deleted.');

        return back();
    }
}
