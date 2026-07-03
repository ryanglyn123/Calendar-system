<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $target */
        $target = $this->route('user');

        // Only pending accounts can be approved, whoever the actor is.
        return $target->isPending() && $this->user()->can('approve', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'division_id' => ['required', 'integer', Rule::exists('divisions', 'id')->where('is_active', true)],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
        ];
    }
}
