<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementDismissalController extends Controller
{
    public function store(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless($announcement->is_dismissible, 403, 'This announcement stays visible.');

        AnnouncementDismissal::query()->firstOrCreate([
            'announcement_id' => $announcement->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(status: 204);
    }
}
