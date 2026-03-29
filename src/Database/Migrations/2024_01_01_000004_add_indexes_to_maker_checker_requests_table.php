<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('maker-checker.table_name', 'maker_checker_requests');

        Schema::table($tableName, function (Blueprint $table) {
            $table->index('maker_id', 'mc_requests_maker_id_index');
            $table->index('checker_id', 'mc_requests_checker_id_index');
            $table->index(['subject_type', 'subject_id', 'status'], 'mc_requests_subject_status_index');
            $table->index(['status', 'created_at'], 'mc_requests_status_created_index');
        });
    }

    public function down(): void
    {
        $tableName = config('maker-checker.table_name', 'maker_checker_requests');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropIndex('mc_requests_maker_id_index');
            $table->dropIndex('mc_requests_checker_id_index');
            $table->dropIndex('mc_requests_subject_status_index');
            $table->dropIndex('mc_requests_status_created_index');
        });
    }
};
