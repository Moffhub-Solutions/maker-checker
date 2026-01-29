<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Sourcetoad\RuleHelper\Rule;

class TestConditionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'configurable_type' => [
                Rule::required(),
                Rule::string(),
            ],
            'action' => [
                Rule::required(),
                Rule::string(),
                Rule::in(['create', 'update', 'delete', 'execute']),
            ],
            'payload' => [
                Rule::required(),
                Rule::array(),
            ],
            'team_id' => [
                Rule::nullable(),
                Rule::integer(),
            ],
        ];
    }
}
