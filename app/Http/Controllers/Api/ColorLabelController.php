<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\UpdateColorLabelsRequest;
use Illuminate\Http\JsonResponse;

/**
 * A person's color labels, saved as one list from the Labels dialog. They
 * belong to that person only: nobody else sees their names for colors.
 */
class ColorLabelController extends Controller
{
    public function update(UpdateColorLabelsRequest $request): JsonResponse
    {
        $labels = collect($request->validated('labels'))
            ->map(fn (array $label) => [
                'color' => strtoupper($label['color']),
                'name' => filled($label['name'] ?? null) ? trim($label['name']) : null,
                'hidden' => (bool) ($label['hidden'] ?? false),
            ])
            ->values()
            ->all();

        $request->user()->forceFill(['color_labels' => $labels])->save();

        return response()->json(['data' => $labels]);
    }
}
