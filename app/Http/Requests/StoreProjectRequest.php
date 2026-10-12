<?php

namespace App\Http\Requests;

use App\Services\PublicGithubRepositoryService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $existingProject = $this->input('project_type') === 'EXISTING_PROJECT';

        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_type' => ['required', Rule::in(['NEW_PROJECT', 'EXISTING_PROJECT'])],
            'stack' => [$existingProject ? 'nullable' : 'required', 'string', 'max:100'],
            'github_repository_url' => [
                $existingProject ? 'required' : 'prohibited',
                'string', 'max:500',
            ],
            'github_branch' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama project wajib diisi.',
            'project_type.required' => 'Pilih jenis project.',
            'project_type.in' => 'Jenis project tidak valid.',
            'stack.required' => 'Stack awal wajib diisi untuk project baru.',
            'github_repository_url.required' => 'URL repository public GitHub wajib diisi.',
            'github_repository_url.prohibited' => 'Project baru tidak boleh menyertakan repository GitHub.',
            'github_branch.regex' => 'Branch GitHub berisi karakter yang tidak diizinkan.',
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            if ($this->input('project_type') !== 'EXISTING_PROJECT') {
                return;
            }

            $github = trim((string) $this->input('github_repository_url'));
            if ($github === '' || $validator->errors()->has('github_repository_url')) {
                return;
            }
            try {
                PublicGithubRepositoryService::normalizeUrl($github);
            } catch (\RuntimeException $exception) {
                $validator->errors()->add('github_repository_url', $exception->getMessage());
            }
        });
    }
}
