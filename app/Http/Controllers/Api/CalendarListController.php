<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Calendar\CalendarListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarListController extends Controller
{
    /**
     * The signed-in user's calendar list with their preferences applied —
     * the single source the sidebar and event grid work from.
     */
    public function __invoke(Request $request, CalendarListService $list): JsonResponse
    {
        return response()->json([
            'data' => $list->forUser($request, $request->user()),
        ]);
    }
}
