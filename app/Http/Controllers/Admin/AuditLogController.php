<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('audit.view'), 403);

        $activities = Activity::query()
            ->with('causer')
            ->when($request->string('event')->isNotEmpty(), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where('description', 'like', "%{$request->string('search')}%"))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/audit/Index', [
            'activities' => ActivityResource::collection($activities),
            'filters' => $request->only(['event', 'search']),
        ]);
    }
}
