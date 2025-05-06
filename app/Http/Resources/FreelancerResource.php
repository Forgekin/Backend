<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreelancerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullname' => $this->fullname,
            'email' => $this->when($this->email_verified_at, $this->email),
            'contact' => $this->contact,
            'gender' => $this->gender,
            'dob' => $this->dob->format('Y-m-d'),
            'age' => $this->dob->age,
            'verification_status' => $this->email_verified_at ? 'verified' : 'pending',
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            
            // Only include sensitive info in development
            'meta' => $this->when(app()->isLocal(), [
                'verification_code' => $this->verification_code,
            ]),
        ];
    }

    /**
     * Add additional meta data to the resource response.
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
            'version' => '1.0',
        ];
    }
}