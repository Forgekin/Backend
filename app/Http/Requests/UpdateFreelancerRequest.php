<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFreelancerRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'fullname' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:freelancers,email,'.$this->freelancer->id,
            'contact' => 'sometimes|string|max:15',
            'password' => 'sometimes|string|min:8|confirmed', // Requires password_confirmation
            'gender' => 'sometimes|in:male,female,other',
            'dob' => 'sometimes|date|before:-18 years',
        ];
    }

    public function messages()
    {
        return [
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
