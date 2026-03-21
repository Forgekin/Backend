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
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'other_names' => $this->other_names,
            'email' => $this->when($this->email_verified_at, $this->email),
            'contact' => $this->contact,
            'profession' => $this->profession,
            'gender' => $this->gender,
            'bio' => $this->bio,
            'dob' => $this->dob ? $this->dob->format('Y-m-d') : null,
            'age' => $this->dob ? $this->dob->diffInYears(now()) : null,
            'verification_status' => $this->email_verified_at ? 'verified' : 'pending',
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),

            // Include profile image
            'profile_image_url' => $this->profile_image ? asset('storage/' . $this->profile_image) : null,

            // Include uploaded documents
            'documents' => $this->documents->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'file_url' => asset('storage/' . $doc->file_path),
                    'file_type' => $doc->file_type,
                    'uploaded_at' => $doc->created_at->format('Y-m-d H:i:s'),
                ];
            }),

            // Include skills
            'skills' => $this->skills->pluck('name'),

            // Include work experiences
            'work_experiences' => $this->workExperiences->map(function ($exp) {
                return [
                    'id' => $exp->id,
                    'role' => $exp->role,
                    'company_name' => $exp->company_name,
                    'start_date' => $exp->start_date,
                    'end_date' => $exp->end_date,
                    'description' => $exp->description,
                ];
            }),

            // Include shift preferences with pivot start/end times
            'shift_preferences' => $this->shifts->map(function ($shift) {
                return [
                    'id' => $shift->id,
                    'name' => $shift->name,
                    'start_time' => optional($shift->pivot)->start_time,
                    'end_time' => optional($shift->pivot)->end_time,
                ];
            }),

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
