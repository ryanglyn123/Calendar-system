<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEventTypeRequest;
use App\Http\Requests\Admin\UpdateEventTypeRequest;
use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EventTypeController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->can('event-types.manage'), 403);

        return Inertia::render('admin/event-types/Index', [
            'eventTypes' => EventTypeResource::collection(
                EventType::query()->orderBy('sort_order')->orderBy('name')->get(),
            ),
        ]);
    }

    public function store(StoreEventTypeRequest $request): RedirectResponse
    {
        EventType::query()->create($request->validated());

        Toast::success('Event type created.');

        return back();
    }

    public function update(UpdateEventTypeRequest $request, EventType $eventType): RedirectResponse
    {
        $eventType->update($request->validated());

        Toast::success('Event type updated.');

        return back();
    }

    public function destroy(EventType $eventType): RedirectResponse
    {
        abort_unless(request()->user()->can('event-types.manage'), 403);

        $eventType->delete();

        Toast::success('Event type deleted.');

        return back();
    }
}
