<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisitorRequest extends FormRequest
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
        return [
            'unit_id' => ['nullable', 'integer', Rule::in($this->user()->activeUnitIds()->all())],
            'name' => ['required', 'string', 'max:100'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'visit_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
                'before_or_equal:'.now()->addDays(30)->format('Y-m-d'),
            ],
        ];
    }
}
