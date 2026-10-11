<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_entity_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unresolved_provider_entity_id')->unique()->constrained()->restrictOnDelete();
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('canonical_id');
            $table->string('reviewer');
            $table->text('reason');
            $table->string('external_name');
            $table->jsonb('context')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_entity_resolutions');
    }
};
