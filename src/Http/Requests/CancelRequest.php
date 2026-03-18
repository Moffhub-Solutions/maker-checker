<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Sourcetoad\RuleHelper\Rule;

class CancelRequest extends FormRequest
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
            'remarks' => [
                Rule::nullable(),
                Rule::string(),
                Rule::max(1000),
            ],
            'note' => [
                Rule::nullable(),
                Rule::string(),
                Rule::max(5000),
            ],
        ];
    }
}
