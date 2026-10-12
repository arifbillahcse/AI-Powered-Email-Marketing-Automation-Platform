<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->boolean('classify_replies')->default(true);
            $table->boolean('auto_label_replies')->default(true);
            $table->boolean('auto_draft_replies')->default(true);
            $table->text('company_context')->nullable(); // what we sell, for drafts and the copilot
            $table->string('meeting_link')->nullable();
        });

        // What the AI made of a reply, and a suggested answer.
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->string('ai_category', 20)->nullable();
            $table->string('ai_summary')->nullable();
            $table->text('ai_draft')->nullable();
            $table->timestamp('ai_processed_at')->nullable();
        });

        Schema::table('inbox_threads', function (Blueprint $table) {
            $table->string('ai_category', 20)->nullable(); // of the latest reply
        });

        Schema::table('ai_usage', function (Blueprint $table) {
            $table->string('purpose', 20)->nullable(); // personalize | classify | draft | enrich | copilot
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage', fn (Blueprint $table) => $table->dropColumn('purpose'));
        Schema::table('inbox_threads', fn (Blueprint $table) => $table->dropColumn('ai_category'));
        Schema::table('inbox_messages', fn (Blueprint $table) => $table->dropColumn(['ai_category', 'ai_summary', 'ai_draft', 'ai_processed_at']));
        Schema::table('ai_settings', fn (Blueprint $table) => $table->dropColumn(['classify_replies', 'auto_label_replies', 'auto_draft_replies', 'company_context', 'meeting_link']));
    }
};
