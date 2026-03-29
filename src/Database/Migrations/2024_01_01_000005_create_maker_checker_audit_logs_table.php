<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('maker-checker.audit.table_name', 'maker_checker_audit_logs');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->index();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('previous_status');
            $table->string('new_status');
            $table->string('ip_address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['actor_type', 'actor_id'], 'mc_audit_actor_index');
            $table->index('action', 'mc_audit_action_index');
        });
    }

    public function down(): void
    {
        $tableName = config('maker-checker.audit.table_name', 'maker_checker_audit_logs');

        Schema::dropIfExists($tableName);
    }
};
