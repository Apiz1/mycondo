<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
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
        $halfHour = '/^([01]\d|2[0-3]):(00|30)$/';

        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'regex:'.$halfHour],
            'end_time' => ['required', 'regex:'.$halfHour, 'after:start_time'],
            'purpose' => ['nullable', 'string', 'max:200'],
        ];
    }
}
