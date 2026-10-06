<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\WorkingHour;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkingHourController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('working-hours.manage'), 403);

        $resourceId = $request->filled('resource') ? $request->integer('resource') : null;

        $rows = WorkingHour::query()
            ->when(
                $resourceId === null,
                fn ($query) => $query->whereNull('resource_id'),
                fn ($query) => $query->where('resource_id', $resourceId),
            )
            ->get()
            ->keyBy('day_of_week');

        return Inertia::render('admin/working-hours/Index', [
            'resourceId' => $resourceId,
            'resources' => Resource::query()->active()->orderBy('name')->get(['id', 'name']),
            'days' => collect(range(0, 6))->map(fn (int $day) => [
                'day_of_week' => $day,
                'opens_at' => $rows->get($day)?->opens_at,
                'closes_at' => $rows->get($day)?->closes_at,
            ]),
        ]);
    }

    /**
     * Replace the weekly schedule for the org default or one resource.
     */
    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('working-hours.manage'), 403);

        $validated = $request->validate([
            'resource_id' => ['nullable', 'integer', Rule::exists('resources', 'id')],
            'days' => ['required', 'array', 'size:7'],
            'days.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6', 'distinct'],
            'days.*.opens_at' => ['nullable', 'date_format:H:i'],
            'days.*.closes_at' => ['nullable', 'date_format:H:i', 'required_with:days.*.opens_at', 'after:days.*.opens_at'],
        ]);

        foreach ($validated['days'] as $day) {
            WorkingHour::query()->updateOrCreate(
                [
                    'resource_id' => $validated['resource_id'] ?? null,
                    'day_of_week' => $day['day_of_week'],
                ],
                [
                    'opens_at' => $day['opens_at'] ?? null,
                    'closes_at' => $day['closes_at'] ?? null,
                ],
            );
        }

        Toast::success('Working hours saved.');

        return back();
    }
}
