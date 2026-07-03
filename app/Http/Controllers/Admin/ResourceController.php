<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\Booking\ResourceService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResourceController extends Controller
{
    public function __construct(
        private readonly ResourceService $resources,
    ) {}

    public function index(): Response
    {
        abort_unless(request()->user()->can('resources.manage'), 403);

        return Inertia::render('admin/resources/Index', [
            'resources' => Resource::query()
                ->with(['type', 'calendar'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Resource $resource) => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'description' => $resource->description,
                    'location' => $resource->location,
                    'capacity' => $resource->capacity,
                    'is_active' => $resource->is_active,
                    'resource_type_id' => $resource->resource_type_id,
                    'type' => $resource->type->name,
                    'color' => $resource->calendar?->color,
                ]),
            'resourceTypes' => ResourceType::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('resources.manage'), 403);

        $this->resources->create($this->validated($request), $request->user());

        Toast::success('Resource created.');

        return back();
    }

    public function update(Request $request, Resource $resource): RedirectResponse
    {
        abort_unless($request->user()->can('resources.manage'), 403);

        $this->resources->update($resource, $this->validated($request));

        Toast::success('Resource updated.');

        return back();
    }

    public function destroy(Resource $resource): RedirectResponse
    {
        abort_unless(request()->user()->can('resources.manage'), 403);

        $this->resources->delete($resource);

        Toast::success('Resource archived.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'resource_type_id' => ['required', 'integer', Rule::exists('resource_types', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['boolean'],
        ]);
    }
}
