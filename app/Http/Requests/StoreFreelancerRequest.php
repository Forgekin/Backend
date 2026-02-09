<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFreelancerRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'other_names' => 'nullable|string|max:255',
            'email' => 'required|email|unique:freelancers,email',
            'contact' => 'required|string|max:15',
            'password' => 'required|string|min:8|confirmed', // Requires password_confirmation
            'gender' => 'required|in:male,female,other',
            'dob' => 'required|date|before:-18 years',
        ];
    }

    public function messages()
    {
        return [
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
