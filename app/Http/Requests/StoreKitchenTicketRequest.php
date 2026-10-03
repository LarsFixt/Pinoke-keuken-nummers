<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreKitchenTicketRequest extends FormRequest
{
    /**
     * Authentication is done by the VerifyTicketBridgeSignature middleware.
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
            'id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'ticket_number' => ['nullable', 'string', 'regex:/^[0-9]{1,6}$/'],
            'ticket_number_confidence' => ['nullable', 'numeric', 'between:0,100'],
            'register' => ['nullable', 'integer', 'between:0,999'],
            'register_name' => ['nullable', 'string', 'max:100'],
            'station' => ['nullable', 'string', 'max:100'],
            'printed_at' => ['nullable', 'date'],
            'captured_at' => ['nullable', 'date'],
            'items' => ['present', 'array', 'max:50'],
            'items.*.qty' => ['required', 'integer', 'between:1,999'],
            'items.*.name' => ['required', 'string', 'max:100'],
            'items.*.notes' => ['present', 'array', 'max:20'],
            'items.*.notes.*' => ['string', 'max:100'],
            'raw_text' => ['nullable', 'string', 'max:5000'],
            'ocr_confidence' => ['nullable', 'numeric', 'between:0,100'],
            'warnings' => ['present', 'array', 'max:20'],
            'warnings.*' => ['string', 'max:200'],
            'image_png_base64' => ['prohibited'],
        ];
    }
}
