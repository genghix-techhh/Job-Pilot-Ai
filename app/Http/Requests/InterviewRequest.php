<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:interview,assessment,follow_up'],
            'title' => ['required', 'string', 'max:190'],
            'application_id' => ['nullable', 'integer'],
            'starts_at_local' => ['nullable', 'date'],
            'ends_at_local' => ['nullable', 'date', 'after:starts_at_local'],
            'deadline_local' => ['nullable', 'date'],
            'source_timezone' => ['required', 'timezone:all'],
            'meeting_url' => ['nullable', 'url:http,https', 'max:490'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'state' => ['nullable', 'in:tentative,confirmed'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if (! $this->filled('starts_at_local') && ! $this->filled('deadline_local')) {
                $v->errors()->add('starts_at_local', 'Enter a start time, or a deadline for assessments and follow-ups.');
            }
        }];
    }

    public function messages(): array
    {
        return ['ends_at_local.after' => 'The end time must be after the start time.'];
    }
}
