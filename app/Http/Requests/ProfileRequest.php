<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $s = 'structured';

        return [
            'roles' => ['nullable', 'string', 'max:1000'],
            'skills' => ['nullable', 'string', 'max:5000'],
            'years_experience' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'experience_levels' => ['nullable', 'array'],
            'experience_levels.*' => ['in:junior,mid,senior'],
            'employment_types' => ['nullable', 'array'],
            'employment_types.*' => ['in:full_time,part_time,contract,freelance,internship'],
            'remote_location' => ['nullable', 'string', 'max:120'],
            'preferred_countries' => ['nullable', 'string', 'max:500'],
            'preferred_timezones' => ['nullable', 'string', 'max:500'],
            'home_country' => ['nullable', 'string', 'max:60'],
            'salary_min' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'salary_currency' => ['nullable', 'alpha', 'size:3'],
            'salary_period' => ['nullable', 'in:year,month,hour'],

            $s => ['required', 'array'],
            "$s.name" => ['nullable', 'string', 'max:120'],
            "$s.headline" => ['nullable', 'string', 'max:160'],
            "$s.email" => ['nullable', 'email', 'max:160'],
            "$s.phone" => ['nullable', 'string', 'max:40'],
            "$s.location" => ['nullable', 'string', 'max:120'],
            "$s.summary" => ['nullable', 'string', 'max:1500'],
            "$s.links" => ['nullable', 'array', 'max:10'],
            "$s.links.*.label" => ['nullable', 'string', 'max:40'],
            "$s.links.*.url" => ['nullable', 'url:http,https', 'max:300'],
            "$s.portfolio_urls" => ['nullable', 'string', 'max:2000'],
            "$s.work_history" => ['nullable', 'array', 'max:15'],
            "$s.work_history.*.title" => ['nullable', 'string', 'max:120'],
            "$s.work_history.*.company" => ['nullable', 'string', 'max:120'],
            "$s.work_history.*.start" => ['nullable', 'string', 'max:30'],
            "$s.work_history.*.end" => ['nullable', 'string', 'max:30'],
            "$s.work_history.*.current" => ['nullable', 'boolean'],
            "$s.work_history.*.bullets" => ['nullable', 'string', 'max:6000'],
            "$s.projects" => ['nullable', 'array', 'max:15'],
            "$s.projects.*.name" => ['nullable', 'string', 'max:120'],
            "$s.projects.*.description" => ['nullable', 'string', 'max:800'],
            "$s.projects.*.technologies" => ['nullable', 'string', 'max:500'],
            "$s.projects.*.url" => ['nullable', 'url:http,https', 'max:300'],
            "$s.education" => ['nullable', 'array', 'max:8'],
            "$s.education.*.degree" => ['nullable', 'string', 'max:160'],
            "$s.education.*.school" => ['nullable', 'string', 'max:160'],
            "$s.education.*.year" => ['nullable', 'string', 'max:20'],
            "$s.certifications" => ['nullable', 'array', 'max:15'],
            "$s.certifications.*.name" => ['nullable', 'string', 'max:160'],
            "$s.certifications.*.issuer" => ['nullable', 'string', 'max:120'],
            "$s.certifications.*.year" => ['nullable', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return [
            'structured.email' => 'contact e-mail',
            'structured.work_history.*.title' => 'job title',
            'structured.projects.*.url' => 'project link',
            'structured.links.*.url' => 'link',
        ];
    }
}
