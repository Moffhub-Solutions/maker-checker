<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('maker-checker.config_table_name', 'maker_checker_configs');

        Schema::table($tableName, function (Blueprint $table) {
            // JSON column for conditional rules
            // Format: {"mode": "all", "rules": [{"field": "amount", "operator": ">=", "value": 50000}]}
            $table->json('conditions')->nullable()->after('unique_fields');

            // Priority for evaluation order (higher = evaluated first)
            $table->integer('priority')->default(0)->after('conditions')->index();
        });

        // Drop the unique constraint on configurable_type + action + team_id
        // since we now allow multiple configs per model/action with different conditions/priorities
        Schema::table($tableName, function (Blueprint $table) {
            $table->dropUnique('maker_checker_configs_unique');

            // Add new unique constraint including priority to prevent exact duplicates
            $table->unique(
                ['configurable_type', 'action', 'team_id', 'priority'],
                'maker_checker_configs_unique_priority'
            );
        });
    }

    public function down(): void
    {
        $tableName = config('maker-checker.config_table_name', 'maker_checker_configs');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropUnique('maker_checker_configs_unique_priority');
            $table->dropColumn(['conditions', 'priority']);

            // Restore original unique constraint
            $table->unique(
                ['configurable_type', 'action', 'team_id'],
                'maker_checker_configs_unique'
            );
        });
    }
};
