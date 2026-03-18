<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maker_checker_approval_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->index();
            $table->string('user_type');
            $table->unsignedBigInteger('user_id');
            $table->string('action');
            $table->text('note');
            $table->timestamps();

            $table->index(['user_type', 'user_id'], 'mc_notes_user_index');

            $table->foreign('request_id')
                ->references('id')
                ->on('maker_checker_requests')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maker_checker_approval_notes');
    }
};
