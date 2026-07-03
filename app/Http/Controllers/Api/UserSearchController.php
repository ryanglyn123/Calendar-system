<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    /**
     * Attendee picker: active users matching a name/email fragment.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $users = User::query()
            ->active()
            ->whereKeyNot($request->user()->id)
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$validated['q']}%")
                ->orWhere('email', 'like', "%{$validated['q']}%"))
            ->with('division')
            ->orderBy('name')
            ->limit(8)
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'division' => $user->division->acronym ?? $user->division->name ?? null,
            ]),
        ]);
    }
}
