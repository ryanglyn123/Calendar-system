<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EditRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\BookingEditRequest;
use App\Services\Booking\EditRequestService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EditRequestController extends Controller
{
    public function __construct(
        private readonly EditRequestService $editRequests,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('bookings.approve'), 403);

        $pending = BookingEditRequest::query()
            ->where('status', EditRequestStatus::Pending->value)
            ->with(['booking.resource.type.approverRole', 'requester'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (BookingEditRequest $editRequest) => $request->user()->can('approve', $editRequest->booking))
            ->values();

        return Inertia::render('admin/edit-requests/Index', [
            'pending' => $pending->map(fn (BookingEditRequest $editRequest) => [
                'id' => $editRequest->id,
                'resource' => $editRequest->booking->resource->name,
                'requester' => $editRequest->requester?->name,
                'note' => $editRequest->note,
                'current' => [
                    'starts_at' => $editRequest->booking->starts_at->toIso8601String(),
                    'ends_at' => $editRequest->booking->ends_at->toIso8601String(),
                    'purpose' => $editRequest->booking->purpose,
                ],
                'proposed' => [
                    'starts_at' => $editRequest->proposed_starts_at?->toIso8601String(),
                    'ends_at' => $editRequest->proposed_ends_at?->toIso8601String(),
                    'purpose' => $editRequest->proposed_purpose,
                ],
            ]),
        ]);
    }

    public function apply(Request $request, BookingEditRequest $editRequest): RedirectResponse
    {
        $this->authorize('approve', $editRequest->booking);

        $this->editRequests->apply($editRequest, $request->user());

        Toast::success('Change applied.');

        return back();
    }

    public function reject(Request $request, BookingEditRequest $editRequest): RedirectResponse
    {
        $this->authorize('approve', $editRequest->booking);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $this->editRequests->reject($editRequest, $request->user(), $validated['reason']);

        Toast::success('Change rejected.');

        return back();
    }
}
