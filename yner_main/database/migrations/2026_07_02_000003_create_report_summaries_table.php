<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cached LLM report summaries (Phase 8, proposal feature 6.6.5). One row per
     * (month, department) — `department_id` null means organization-wide. The unique key lets
     * the "Generate AI summary" action upsert, so each month is summarized by Claude at most
     * once unless explicitly regenerated. `stats` stores the exact aggregate payload sent
     * externally (privacy audit trail — proves no PII left the system).
     */
    public function up(): void
    {
        Schema::create('report_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('period_month'); // first day of the summarized month
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->text('narrative');
            $table->json('highlights')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('stats')->nullable();
            $table->string('model')->nullable();
            $table->boolean('fallback')->default(false);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_month', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_summaries');
    }
};
