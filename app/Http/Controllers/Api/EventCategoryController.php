<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\StoreEventCategoryRequest;
use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use Illuminate\Http\JsonResponse;

/**
 * A user's private event categories: event types only their owner sees,
 * added straight from the Create menu.
 */
class EventCategoryController extends Controller
{
    /** Google Calendar colors, handed out in turn. */
    private const COLORS = ['#039BE5', '#33B679', '#D81B60', '#7986CB', '#F09300', '#E67C73', '#F6BF26', '#9E69AF'];

    public function store(StoreEventCategoryRequest $request): JsonResponse
    {
        $user = $request->user();
        $count = EventType::query()->where('user_id', $user->id)->count();

        $category = EventType::query()->create([
            'user_id' => $user->id,
            'name' => $request->validated('name'),
            'color' => self::COLORS[$count % count(self::COLORS)],
            'sort_order' => $count,
        ]);

        return EventTypeResource::make($category)->response()->setStatusCode(201);
    }
}
