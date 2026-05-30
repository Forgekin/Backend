<?php

namespace App\Http\Requests;

use App\Models\Employer;
use App\Models\Freelancer;
use Illuminate\Foundation\Http\FormRequest;

class PasswordResetRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                // Accept the email if it belongs to any supported account type.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $email = strtolower(trim((string) $value));

                    $exists = Freelancer::where('email', $email)->exists()
                        || Employer::where('email', $email)->exists();

                    if (!$exists) {
                        $fail('The selected email is invalid.');
                    }
                },
            ],
        ];
    }
}
