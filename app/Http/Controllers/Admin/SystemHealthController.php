<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Toast;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $failer = app('queue.failer');
        $retried = 0;
        $dropped = 0;

        // One at a time: queue:retry rebuilds each job, and a job about a
        // booking or request that has since been deleted can never run.
        // Retrying "all" stops at the first such job with a 404.
        foreach ($failer->ids() as $id) {
            try {
                Artisan::call('queue:retry', ['id' => [$id]]);
                $retried++;
            } catch (ModelNotFoundException) {
                $failer->forget($id);
                $dropped++;
            }
        }

        match (true) {
            $retried + $dropped === 0 => Toast::success('No failed jobs to retry.'),
            $dropped === 0 => Toast::success("{$retried} failed ".str('job')->plural($retried).' queued for retry.'),
            default => Toast::success(
                "{$retried} failed ".str('job')->plural($retried).' queued for retry. '
                ."{$dropped} removed: the booking or request ".($dropped === 1 ? 'it was' : 'they were').' about no longer exists.'
            ),
        };

        return back();
    }
}
