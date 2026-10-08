<?php

namespace App\Http\Requests;

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
            'source_archive' => [
                $existingProject ? 'required' : 'prohibited',
                'file',
                'mimes:zip',
                'max:102400',
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
            'source_archive.required' => 'Upload file ZIP untuk project existing.',
            'source_archive.prohibited' => 'Project baru tidak boleh menyertakan arsip source.',
            'source_archive.mimes' => 'Source project harus berupa file ZIP.',
            'source_archive.max' => 'Ukuran ZIP maksimal 100 MB.',
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $archive = $this->file('source_archive');

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
