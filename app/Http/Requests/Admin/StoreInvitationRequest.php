<?php

namespace App\Http\Requests\Admin;

use App\Models\Invitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invitation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')->where('is_active', true)],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email already exists.',
        ];
    }
}
