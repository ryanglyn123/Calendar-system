<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DivisionResource;
use App\Models\Announcement;
use App\Models\Division;
use App\Services\Admin\AdminDashboard;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->can('announcements.manage'), 403);

        return Inertia::render('admin/announcements/Index', [
            // Not 'announcements': that key is the shared banner prop.
            'rows' => Announcement::query()
                ->with('division')
                ->orderByDesc('starts_at')
                ->get()
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'level' => $announcement->level,
                    'starts_at' => $announcement->starts_at->toDateString(),
                    'ends_at' => $announcement->ends_at?->toDateString(),
                    'division_id' => $announcement->division_id,
                    'division' => $announcement->division?->name,
                    'is_dismissible' => $announcement->is_dismissible,
                    'is_active' => $announcement->is_active,
                ]),
            'divisions' => DivisionResource::collection(Division::query()->active()->orderBy('name')->get()),
            // The office's date, so the page can tell live from scheduled and ended.
            'today' => now(AdminDashboard::timezone())->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('announcements.manage'), 403);

        Announcement::query()->create([
            ...$this->validated($request),
            'created_by' => $request->user()->id,
        ]);

        Toast::success('Announcement published.');

        return back();
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        abort_unless($request->user()->can('announcements.manage'), 403);

        $announcement->update($this->validated($request));

        Toast::success('Announcement updated.');

        return back();
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        abort_unless(request()->user()->can('announcements.manage'), 403);

        $announcement->delete();

        Toast::success('Announcement removed.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'level' => ['required', Rule::in(['info', 'warning', 'critical'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
            'is_dismissible' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
