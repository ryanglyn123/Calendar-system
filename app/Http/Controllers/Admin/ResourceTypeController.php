<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\ResourceType;
use App\Models\Role;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResourceTypeController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->can('resource-types.manage'), 403);

        return Inertia::render('admin/resource-types/Index', [
            'resourceTypes' => ResourceType::query()
                ->with('approverRole')
                ->withCount('resources')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ResourceType $type) => $this->serialize($type)),
            'roles' => RoleResource::collection(Role::query()->orderBy('name')->get()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('resource-types.manage'), 403);

        $validated = $this->validated($request);

        ResourceType::query()->create([
            ...$validated,
            'slug' => Str::slug($validated['name']),
        ]);

        Toast::success('Resource type created.');

        return back();
    }

    public function update(Request $request, ResourceType $resourceType): RedirectResponse
    {
        abort_unless($request->user()->can('resource-types.manage'), 403);

        $resourceType->update($this->validated($request, $resourceType));

        Toast::success('Resource type updated.');

        return back();
    }

    public function destroy(ResourceType $resourceType): RedirectResponse
    {
        abort_unless(request()->user()->can('resource-types.manage'), 403);
        abort_if($resourceType->resources()->withTrashed()->exists(), 403, 'Delete or move its resources (including archived ones) first.');

        $resourceType->delete();

        Toast::success('Resource type deleted.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ResourceType $existing = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100', Rule::unique('resource_types', 'name')->ignore($existing),
                // The URL slug is derived from the name and unique as well ("Board Room" vs "Board-Room").
                function (string $attribute, mixed $value, \Closure $fail) use ($existing) {
                    $taken = ResourceType::query()
                        ->where('slug', Str::slug((string) $value))
                        ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
                        ->exists();

                    if ($taken) {
                        $fail('Another resource type already uses this name\'s URL slug.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'approver_role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'requires_approval' => ['boolean'],
            'min_lead_time_minutes' => ['integer', 'min:0', 'max:20160'],
            'max_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:20160'],
            'max_advance_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_active_bookings_per_user' => ['nullable', 'integer', 'min:1', 'max:100'],
            'enforce_working_hours' => ['boolean'],
            'block_holidays' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ResourceType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'description' => $type->description,
            'approver_role_id' => $type->approver_role_id,
            'approver_role' => $type->approverRole?->name,
            'requires_approval' => $type->requires_approval,
            'min_lead_time_minutes' => $type->min_lead_time_minutes,
            'max_duration_minutes' => $type->max_duration_minutes,
            'max_advance_days' => $type->max_advance_days,
            'max_active_bookings_per_user' => $type->max_active_bookings_per_user,
            'enforce_working_hours' => $type->enforce_working_hours,
            'block_holidays' => $type->block_holidays,
            'is_active' => $type->is_active,
            'resources_count' => $type->resources_count ?? 0,
        ];
    }
}
