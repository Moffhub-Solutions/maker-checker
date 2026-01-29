<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Moffhub\MakerChecker\Services\ConditionEvaluator;
use Sourcetoad\RuleHelper\Rule;

class UpdateConfigRequest extends FormRequest
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
            'description' => [
                Rule::nullable(),
                Rule::string(),
                Rule::max(500),
            ],
            'is_active' => [
                Rule::nullable(),
                Rule::boolean(),
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->has('conditions')) {
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
            }
        });
    }

    /**
     * Get the update data array.
     *
     * @return array<string, mixed>
     */
    public function getUpdateData(): array
    {
        $updateData = [];

        if ($this->has('approvals')) {
            $approvals = $this->input('approvals', []);
            $updateData['approvals'] = array_filter([
                'roles' => $approvals['roles'] ?? [],
                'users' => $approvals['users'] ?? [],
            ], fn($v) => !empty($v));
        }

        if ($this->has('unique_fields')) {
            $updateData['unique_fields'] = $this->input('unique_fields');
        }

        if ($this->has('conditions')) {
            $updateData['conditions'] = $this->input('conditions');
        }

        if ($this->has('priority')) {
            $updateData['priority'] = $this->input('priority');
        }

        if ($this->has('description')) {
            $updateData['description'] = $this->input('description');
        }

        if ($this->has('is_active')) {
            $updateData['is_active'] = $this->boolean('is_active');
        }

        return $updateData;
    }
}
