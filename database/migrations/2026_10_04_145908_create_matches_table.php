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
        Schema::create('matches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('competition_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('home_team_id')
                ->constrained('teams')
                ->restrictOnDelete();

            $table->foreignId('away_team_id')
                ->constrained('teams')
                ->restrictOnDelete();

            $table->timestampTz('starts_at');
            $table->string('status');

            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();

            $table->timestamps();

            $table->index(['competition_id', 'starts_at']);
            $table->index(['home_team_id', 'starts_at']);
            $table->index(['away_team_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
