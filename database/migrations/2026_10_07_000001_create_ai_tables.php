<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One per workspace: which AI provider, key and model to use.
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('platform'); // platform | anthropic | openai
            $table->text('api_key')->nullable(); // encrypted; BYOK only
            $table->string('model')->nullable();
            $table->timestamps();
        });

        // Reusable instructions: what you sell, to whom, and how to write.
        Schema::create('ai_prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20); // first_line | subject_line | email_body
            $table->text('instructions');
            $table->string('tone', 30)->default('friendly');
            $table->string('language', 40)->default('English');
            $table->string('length', 10)->default('short');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        // The review queue: one AI output per lead per campaign step per type.
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_prompt_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->text('output')->nullable();
            $table->boolean('edited')->default(false);
            $table->text('error')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('model')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_step_id', 'lead_id', 'type']);
            $table->index(['workspace_id', 'status']);
            $table->index(['campaign_id', 'status']);
        });

        // Every AI call, for usage limits and cost reporting.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('ai_prompt_templates');
        Schema::dropIfExists('ai_settings');
    }
};
