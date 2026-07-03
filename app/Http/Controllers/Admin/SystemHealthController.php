<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SystemHealthController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return Inertia::render('admin/health/Index', [
            'queuedJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')
                ->latest('failed_at')
                ->limit(20)
                ->get(['id', 'queue', 'exception', 'failed_at'])
                ->map(fn ($job) => [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'summary' => str($job->exception)->before("\n")->limit(160)->toString(),
                ]),
        ]);
    }

    public function retry(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        Artisan::call('queue:retry', ['id' => ['all']]);

        Toast::success('Failed jobs queued for retry.');

        return back();
    }
}
