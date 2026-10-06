<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\User;
use App\Services\Admin\AdminDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('audit.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:255'],
            'causer' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $userMorph = (new User)->getMorphClass();

        // Dates are office days (configured timezone); timestamps are stored in UTC.
        $activities = Activity::query()
            ->with('causer')
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('description', 'like', "%{$search}%"))
            ->when($filters['causer'] ?? null, fn ($q, $causer) => $q
                ->where('causer_type', $userMorph)
                ->where('causer_id', $causer))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q
                ->where('created_at', '>=', Carbon::parse($from, AdminDashboard::timezone())->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q
                ->where('created_at', '<=', Carbon::parse($to, AdminDashboard::timezone())->endOfDay()->utc()))
            ->latest()
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/audit/Index', [
            'activities' => ActivityResource::collection($activities),
            'events' => Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
            'actors' => User::query()
                ->whereIn('id', Activity::query()->where('causer_type', $userMorph)->select('causer_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => $request->only(['search', 'event', 'causer', 'from', 'to']),
        ]);
    }
}
