<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Moffhub\MakerChecker\Services\ConditionEvaluator;
use Sourcetoad\RuleHelper\Rule;

class StoreConfigRequest extends FormRequest
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
                Rule::nullable(),
                Rule::string(),
                Rule::in(['create', 'update', 'delete', 'execute']),
            ],
            'approvals' => [
                Rule::nullable(),
                Rule::array(),
            ],
            'unique_fields' => [
                Rule::nullable(),
                Rule::array(),
            ],
            'unique_fields.*' => [
                Rule::string(),
            ],
            'conditions' => [
                Rule::nullable(),
                Rule::array(),
            ],
            'priority' => [
                Rule::nullable(),
                Rule::integer(),
                Rule::min(0),
                Rule::max(10000),
            ],
            'team_id' => [
                Rule::nullable(),
                Rule::integer(),
            ],
            'description' => [
                Rule::nullable(),
                Rule::string(),
                Rule::max(500),
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $conditions = $this->input('conditions');
            if ($conditions !== null) {
                $evaluator = new ConditionEvaluator;
                $errors = $evaluator->validate($conditions);
                if ($errors !== []) {
                    foreach ($errors as $error) {
                        $validator->errors()->add('conditions', $error);
                    }
                }
            }
        });
    }

    /**
     * Get the validated approvals in normalized format.
     *
     * @return array{roles?: array<string, int>, users?: array<string>}
     */
    public function normalizedApprovals(): array
    {
        $approvals = $this->input('approvals', []);

        return array_filter([
            'roles' => $approvals['roles'] ?? [],
            'users' => $approvals['users'] ?? [],
        ], fn($v) => !empty($v));
    }
}
