<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSprintScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'story_ids' => ['sometimes', 'array', 'max:100'],
            'story_ids.*' => ['required', 'integer', 'distinct', 'exists:stories,id'],
        ];
    }
}
