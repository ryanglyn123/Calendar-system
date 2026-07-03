<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use App\Settings\GeneralSettings;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(GeneralSettings $settings): Response
    {
        return Inertia::render('Dashboard', [
            'eventTypes' => EventTypeResource::collection(
                EventType::query()->active()->orderBy('sort_order')->get(),
            ),
            'weekStartsOn' => $settings->week_starts_on,
        ]);
    }
}
