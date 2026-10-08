<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_profile' => ['required', Rule::in(['backend-developer', 'frontend-developer'])],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul task wajib diisi.',
            'assignee_profile.required' => 'Pilih profil Developer untuk task ini.',
            'assignee_profile.in' => 'Task hanya dapat ditugaskan ke Backend atau Frontend Developer.',
        ];
    }
}
