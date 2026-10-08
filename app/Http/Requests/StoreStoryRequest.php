<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'actor_id' => ['required', 'integer', Rule::exists('actors', 'id')->where('is_active', true)],
            'sprint_purpose' => ['required', 'string', 'min:3', 'max:3000'],
            'acceptance_criteria' => ['required', 'string', 'min:10', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul story wajib diisi.',
            'actor_id.required' => 'Pilih aktor yang mengusulkan user story.',
            'sprint_purpose.required' => 'Tujuan user story wajib diisi.',
            'acceptance_criteria.required' => 'Acceptance Criteria wajib diisi.',
        ];
    }
}
