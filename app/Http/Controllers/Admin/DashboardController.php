<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'total_users' => User::query()->count(),
                'pending_users' => User::query()->pending()->count(),
                'divisions' => Division::query()->active()->count(),
            ],
        ]);
    }
}
