<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $announcement_id
 * @property int $user_id
 */
#[Fillable(['announcement_id', 'user_id'])]
class AnnouncementDismissal extends Model {}
