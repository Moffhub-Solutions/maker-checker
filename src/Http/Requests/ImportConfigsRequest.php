<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Sourcetoad\RuleHelper\Rule;

class ImportConfigsRequest extends FormRequest
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
            'configs' => [
                Rule::required(),
                Rule::array(),
            ],
            'configs.*.configurable_type' => [
                Rule::required(),
                Rule::string(),
            ],
            'configs.*.action' => [
                Rule::nullable(),
                Rule::string(),
                Rule::in(['create', 'update', 'delete', 'execute']),
            ],
            'configs.*.approvals' => [
                Rule::nullable(),
                Rule::array(),
            ],
            'configs.*.unique_fields' => [
                Rule::nullable(),
                Rule::array(),
            ],
            'configs.*.conditions' => [
                Rule::nullable(),
                Rule::array(),
            ],
            'configs.*.priority' => [
                Rule::nullable(),
                Rule::integer(),
                Rule::min(0),
                Rule::max(10000),
            ],
            'configs.*.team_id' => [
                Rule::nullable(),
                Rule::integer(),
            ],
            'configs.*.description' => [
                Rule::nullable(),
                Rule::string(),
                Rule::max(500),
            ],
        ];
    }

    /**
     * Get configs with normalized approvals.
     *
     * @return array<int, array<string, mixed>>
     */
    public function normalizedConfigs(): array
    {
        return array_map(function ($config) {
            if (isset($config['approvals'])) {
                $config['approvals'] = array_filter([
                    'roles' => $config['approvals']['roles'] ?? [],
                    'users' => $config['approvals']['users'] ?? [],
                ], fn($v) => !empty($v));
            }

            return $config;
        }, $this->input('configs', []));
    }
}
