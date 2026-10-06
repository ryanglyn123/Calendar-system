<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Admin\AdminDashboard;
use App\Services\Booking\BookingService;
use App\Services\Booking\ReviewQueue;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BookingApprovalController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
    ) {}

    /**
     * Pending requests scoped to what the signed-in reviewer may approve,
     * plus one day's schedule (`date`, default today), so a request can be
     * judged against everything else its resource holds that day.
     */
    public function index(Request $request, AdminDashboard $dashboard): Response
    {
        abort_unless($request->user()->can('bookings.approve'), 403);

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $today = CarbonImmutable::now(AdminDashboard::timezone())->startOfDay();

        return $this->page($request, $dashboard, isset($validated['date'])
            ? CarbonImmutable::parse($validated['date'], AdminDashboard::timezone())->startOfDay()
            : $today);
    }

    /**
     * One request's review page: where notifications, the top-bar list and
     * queue rows link. It always lands somewhere true: a request decided by
     * someone else shows that outcome, a removed or unknown one says so.
     * Details are only ever shown to a reviewer of that resource type.
     */
    public function show(Request $request, AdminDashboard $dashboard, ReviewQueue $queue, string $booking): Response
    {
        abort_unless($request->user()->can('bookings.approve'), 403);

        $model = ctype_digit($booking)
            ? Booking::withTrashed()->with('resource.type')->find((int) $booking)
            : null;

        if ($model === null) {
            return $this->page($request, $dashboard, CarbonImmutable::now(AdminDashboard::timezone())->startOfDay(), [
                'id' => ctype_digit($booking) ? (int) $booking : null,
                'missing' => true,
            ]);
        }

        $this->authorize('approve', $model);

        return $this->page(
            $request,
            $dashboard,
            CarbonImmutable::parse($model->starts_at)->timezone(AdminDashboard::timezone())->startOfDay(),
            fn () => $queue->detail($model),
        );
    }

    public function approve(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('approve', $booking);

        $version = $request->validate(['version' => ['nullable', 'string']])['version'] ?? null;

        $this->decide(fn () => $this->bookings->approve($booking, $request->user(), $version));

        Toast::success('Booking approved.');

        return back();
    }

    public function reject(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('approve', $booking);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'version' => ['nullable', 'string'],
        ]);

        $this->decide(fn () => $this->bookings->reject(
            $booking,
            $request->user(),
            $validated['reason'],
            $validated['version'] ?? null,
        ));

        Toast::success('Booking rejected.');

        return back();
    }

    /**
     * @param  array<string, mixed>|Closure|null  $focus  the request under review, if any
     */
    private function page(Request $request, AdminDashboard $dashboard, CarbonImmutable $day, array|Closure|null $focus = null): Response
    {
        $user = $request->user();

        return Inertia::render('admin/bookings/Index', [
            'today' => CarbonImmutable::now(AdminDashboard::timezone())->startOfDay()->toDateString(),
            'requests' => fn () => $dashboard->bookingRequests($user),
            'schedule' => fn () => $dashboard->schedule($user, $day),
            'focus' => $focus,
        ]);
    }

    /**
     * A decision made on stale data (someone else acted first) comes back as
     * a form error on the review page, not an error screen; the page then
     * reloads with the booking's real state.
     */
    private function decide(Closure $action): void
    {
        try {
            $action();
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }
    }
}
