<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Notifications\BookingRequestedNotification;
use App\Services\Booking\ReviewQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * The bell's list. Every booking notice carries its booking's live
     * state, so an "approved" that was cancelled since says so. "Approval
     * needed" notices are also settled on every read: one somebody already
     * decided (or removed) is marked read and says what happened. Approvers'
     * notices link to the review page, requesters' to the booking itself.
     * The live review summary rides along for the top bar.
     */
    public function index(Request $request, ReviewQueue $queue): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()->latest()->limit(30)->get();

        $requests = $notifications->filter(
            fn (DatabaseNotification $n) => ($n->data['key'] ?? null) === BookingRequestedNotification::KEY,
        );

        $aboutBookings = $notifications->filter(
            fn (DatabaseNotification $n) => isset($n->data['booking_id']),
        );

        $bookings = Booking::withTrashed()
            ->findMany($aboutBookings->pluck('data.booking_id')->unique())
            ->keyBy('id');

        $stateOf = function (DatabaseNotification $n) use ($bookings): string {
            $booking = $bookings->get($n->data['booking_id'] ?? 0);

            return $booking === null || $booking->trashed() ? 'removed' : $booking->status->value;
        };

        $settled = $requests->filter(
            fn (DatabaseNotification $n) => $n->read_at === null && $stateOf($n) !== BookingStatus::Pending->value,
        );

        if ($settled->isNotEmpty()) {
            DatabaseNotification::query()->whereKey($settled->modelKeys())->update(['read_at' => now()]);
            $settled->each(fn (DatabaseNotification $n) => $n->read_at = now());
        }

        return response()->json([
            'data' => $notifications->map(function (DatabaseNotification $notification) use ($stateOf) {
                $bookingId = $notification->data['booking_id'] ?? null;
                $isRequest = ($notification->data['key'] ?? null) === BookingRequestedNotification::KEY;

                return [
                    'id' => $notification->id,
                    'key' => $notification->data['key'] ?? null,
                    'title' => $notification->data['title'] ?? 'Notification',
                    'message' => $notification->data['message'] ?? '',
                    // Rebuilt here so notices sent before a route moved still land.
                    'action_url' => match (true) {
                        $bookingId === null => $notification->data['action_url'] ?? null,
                        $isRequest => route('admin.bookings.show', $bookingId, absolute: false),
                        default => route('bookings.show', $bookingId, absolute: false),
                    },
                    'booking_id' => $bookingId,
                    'booking_status' => $bookingId === null ? null : $stateOf($notification),
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ];
            }),
            'unread_count' => $user->unreadNotifications()->count(),
            'review' => $queue->summary($user),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);

        $notification->markAsRead();

        return response()->json(['read_at' => $notification->read_at?->toIso8601String()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread_count' => 0]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json(status: 204);
    }
}
