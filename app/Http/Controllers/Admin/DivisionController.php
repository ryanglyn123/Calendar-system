<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDivisionRequest;
use App\Http\Requests\Admin\UpdateDivisionRequest;
use App\Http\Resources\DivisionResource;
use App\Models\Division;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DivisionController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Division::class);

        return Inertia::render('admin/divisions/Index', [
            'divisions' => DivisionResource::collection(
                Division::query()->withCount('users')->orderBy('sort_order')->orderBy('name')->get(),
            ),
        ]);
    }

    public function store(StoreDivisionRequest $request): RedirectResponse
    {
        Division::query()->create($request->validated());

        Toast::success('Division created.');

        return back();
    }

    public function update(UpdateDivisionRequest $request, Division $division): RedirectResponse
    {
        $division->update($request->validated());

        Toast::success('Division updated.');

        return back();
    }

    public function destroy(Division $division): RedirectResponse
    {
        $this->authorize('delete', $division);

        abort_if($division->users()->exists(), 403, 'Divisions with members cannot be deleted.');

        $division->delete();

        Toast::success('Division deleted.');

        return back();
    }
}
