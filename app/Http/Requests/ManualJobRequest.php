<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'url:http,https', 'max:1900'],
            'title' => ['nullable', 'string', 'max:250'],
            'company' => ['nullable', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:60000'],
            'location' => ['nullable', 'string', 'max:250'],
            'kind' => ['nullable', 'in:employment,freelance'],
            'remote' => ['nullable', 'boolean'],
            'salary_min' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'salary_max' => ['nullable', 'integer', 'min:0', 'max:100000000', 'gte:salary_min'],
            'salary_currency' => ['nullable', 'alpha', 'size:3'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.url' => 'Enter a full link starting with http:// or https://.',
            'salary_max.gte' => 'The maximum salary cannot be lower than the minimum.',
        ];
    }
}
