<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\User;
use Illuminate\Validation\Rule;

class StoreResidentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
   public function rules(): array
    {
        $isNewUser = fn () => ! User::where('email', $this->input('email'))->exists();

        return [
            'email' => ['required', 'email'],
            'name' => [Rule::requiredIf($isNewUser), 'nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [Rule::requiredIf($isNewUser), 'nullable', 'string', 'min:8'],
            'relationship' => ['required', Rule::in(['owner', 'tenant'])],
            'moved_in_at' => ['nullable', 'date'],
        ];
    }
}
