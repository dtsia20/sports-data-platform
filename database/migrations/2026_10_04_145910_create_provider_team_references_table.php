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
        Schema::create('provider_team_references', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')
                ->constrained('sports_data_providers')
                ->cascadeOnDelete();

            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('external_id');

            $table->timestamps();

            $table->unique(['provider_id', 'external_id']);
            $table->unique(['provider_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_team_references');
    }
};
