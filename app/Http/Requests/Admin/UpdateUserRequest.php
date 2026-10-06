<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $target */
        $target = $this->route('user');

        // Super admin accounts are edited by super admins only; otherwise a
        // user manager could strip or take over the one role above theirs.
        if ($target->hasRole('super_admin') && ! $this->user()->hasRole('super_admin')) {
            return false;
        }

        return $this->user()->can('update', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name'), new AssignableRole($this->user())],
        ];
    }
}
