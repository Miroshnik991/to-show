<?php

namespace App\Http\Requests\Sberpay;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class SberpayCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mdOrder' => 'required|string|uuid|max:36',
            'orderNumber' => 'required|string|max:36',
            'operation' => 'required|string|max:20',
            'status' => 'required|integer|in:0,1',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $this->logValidationError($validator);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }

    protected function logValidationError(Validator $validator): void
    {
        Log::error('Sberpay callback validation failed', [
            'errors' => $validator->errors()->all(),
            'input' => $this->all(),
            'ip' => $this->ip(),
            'url' => $this->fullUrl(),
        ]);
    }
}
