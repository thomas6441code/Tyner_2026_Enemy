<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persisted absenteeism/lateness risk scores from the AI service (Phase 7). One row per
     * employee holds their latest score; `ai:score-attendance` upserts on `employee_id`.
     */
    public function up(): void
    {
        Schema::create('ai_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->float('risk_score');
            $table->string('risk_level'); // low | medium | high
            $table->json('top_factors')->nullable();
            $table->string('model_version')->nullable();
            $table->dateTime('computed_at');
            $table->timestamps();

            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_predictions');
    }
};
