<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_country_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions')->restrictOnDelete();
            $table->string('previous_country', 120)->nullable();
            $table->string('new_country', 120)->nullable();
            $table->string('reviewer', 120);
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['competition_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_country_revisions');
    }
};
