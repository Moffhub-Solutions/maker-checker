<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maker_checker_delegations', function (Blueprint $table) {
            $table->id();
            $table->string('delegator_type');
            $table->unsignedBigInteger('delegator_id');
            $table->string('delegate_type');
            $table->unsignedBigInteger('delegate_id');
            $table->string('scope')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['delegator_type', 'delegator_id'], 'mc_delegations_delegator_index');
            $table->index(['delegate_type', 'delegate_id'], 'mc_delegations_delegate_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maker_checker_delegations');
    }
};
