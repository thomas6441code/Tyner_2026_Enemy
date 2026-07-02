<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persisted anomaly-detection results from the AI service (Phase 7). Each row is one
     * flagged (employee, work_date, method) tuple; the unique key lets `ai:score-attendance`
     * upsert idempotently on every nightly run.
     */
    public function up(): void
    {
        Schema::create('ai_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('method'); // isolation_forest | zscore
            $table->float('score');
            $table->text('explanation');
            $table->json('features')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_anomalies');
    }
};
