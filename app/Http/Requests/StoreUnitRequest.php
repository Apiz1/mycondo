<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unit_type_id' => ['required', 'exists:unit_types,id'],
            'block' => ['nullable', 'string', 'max:20'],
            'unit_number' => [
                'required', 'string', 'max:20',
                Rule::unique('units')->where(fn ($q) => $q->where('block', $this->block)),
            ],
        ];
    }
}
