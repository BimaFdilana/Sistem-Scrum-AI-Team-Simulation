<?php

namespace App\Http\Requests;

use App\Services\PublicGithubRepositoryService;
use App\Support\Archives\ZipArchiveInspector;
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
                $existingProject ? 'nullable' : 'prohibited',
                'nullable', 'string', 'max:500',
            ],
            'github_branch' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'source_archive' => [
                $existingProject ? 'nullable' : 'prohibited',
                'file', 'mimes:zip', 'max:102400',
            ],
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
            'source_archive.prohibited' => 'Project baru tidak boleh menyertakan arsip source.',
            'source_archive.mimes' => 'Source project harus berupa file ZIP.',
            'source_archive.max' => 'Ukuran ZIP maksimal 100 MB.',
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            if ($this->input('project_type') !== 'EXISTING_PROJECT') {
                return;
            }

            $github = trim((string) $this->input('github_repository_url'));
            $archive = $this->file('source_archive');
            if ($github === '' && ! $archive) {
                $validator->errors()->add('github_repository_url', 'Masukkan URL repository public GitHub.');
                $validator->errors()->add('source_archive', 'Upload ZIP legacy atau masukkan URL repository public GitHub.');

                return;
            }
            if ($github !== '') {
                try {
                    PublicGithubRepositoryService::normalizeUrl($github);
                } catch (\RuntimeException $exception) {
                    $validator->errors()->add('github_repository_url', $exception->getMessage());
                }

                return;
            }

            if (! $archive || $validator->errors()->has('source_archive')) {
                return;
            }
            try {
                app(ZipArchiveInspector::class)->assertSafeMetadata($archive->getRealPath());
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('source_archive', $exception->getMessage());
            }
        });
    }
}
