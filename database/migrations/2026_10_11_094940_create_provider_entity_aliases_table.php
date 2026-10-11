<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_entity_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('sports_data_providers')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('reviewer');
            $table->text('reason');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_by')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['provider_id', 'team_id', 'normalized_name'], 'provider_team_alias_unique');
            $table->unique(['provider_id', 'competition_id', 'normalized_name'], 'provider_competition_alias_unique');
        });
        DB::statement('ALTER TABLE provider_entity_aliases ADD CONSTRAINT alias_has_one_target CHECK ((team_id IS NOT NULL AND competition_id IS NULL) OR (team_id IS NULL AND competition_id IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_entity_aliases');
    }
};
