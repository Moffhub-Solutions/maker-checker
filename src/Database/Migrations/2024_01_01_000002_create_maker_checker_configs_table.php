<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('maker-checker.config_table_name', 'maker_checker_configs'), function (Blueprint $table) {
            $table->id();

            // The model class or executable class this config applies to
            $table->string('configurable_type')->index();

            // The action type (create, update, delete, execute) - null means all actions
            $table->string('action')->nullable()->index();

            // Role-based approval requirements: {"admin": 2, "manager": 1}
            $table->json('approvals')->default('[]');

            // Fields to check for uniqueness: ["email", "phone"]
            $table->json('unique_fields')->default('[]');

            // Whether this config is active
            $table->boolean('is_active')->default(true)->index();

            // Optional team ID for multi-tenant configs
            $table->unsignedBigInteger('team_id')->nullable()->index();

            // Additional metadata for custom use
            $table->json('metadata')->nullable();

            // Human-readable description/notes
            $table->text('description')->nullable();

            $table->timestamps();

            // Ensure unique config per model/action/team combination
            $table->unique(['configurable_type', 'action', 'team_id'], 'maker_checker_configs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('maker-checker.config_table_name', 'maker_checker_configs'));
    }
};
