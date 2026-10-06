<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('unresolved_provider_entities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')
                ->constrained('sports_data_providers')
                ->cascadeOnDelete();

            $table->string('entity_type', 50);

            $table->string('external_id');

            $table->string('external_name');

            $table->jsonb('context')
                ->nullable();

            $table->string('status', 50)
                ->default('pending');

            $table->unsignedInteger('occurrences_count')
                ->default(1);

            $table->timestampTz('first_seen_at');

            $table->timestampTz('last_seen_at');

            $table->timestampTz('resolved_at')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'provider_id',
                'entity_type',
                'external_id',
            ]);

            $table->index([
                'provider_id',
                'status',
            ]);

            $table->index([
                'entity_type',
                'status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unresolved_provider_entities');
    }
};
