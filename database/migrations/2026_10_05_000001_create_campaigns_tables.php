<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_template')->default(false);

            // Schedule (lead-facing times are in this time zone).
            $table->string('timezone')->default('UTC');
            $table->json('send_days');
            $table->string('send_window_start', 5);
            $table->string('send_window_end', 5);
            $table->unsignedInteger('daily_limit');

            // Options
            $table->boolean('stop_on_reply')->default(true);
            $table->boolean('track_opens')->default(false);
            $table->boolean('track_clicks')->default(false);
            $table->boolean('plain_text')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // Days to wait after the previous step (0 for the first step).
            $table->unsignedSmallInteger('delay_days')->default(0);
            // Blank on a follow-up = reply in the same thread ("Re: ...").
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['campaign_id', 'position']);
        });

        Schema::create('campaign_email_account', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->primary(['campaign_id', 'email_account_id']);
        });

        Schema::create('campaign_lead_list', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_list_id')->constrained()->cascadeOnDelete();
            $table->primary(['campaign_id', 'lead_list_id']);
        });

        Schema::create('campaign_segment', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('segment_id')->constrained()->cascadeOnDelete();
            $table->primary(['campaign_id', 'segment_id']);
        });

        // Leads enrolled in a campaign and where they are in its sequence.
        Schema::create('campaign_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            // Sticky sender, so follow-ups stay in one thread (set in Phase 5).
            $table->foreignId('email_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active');
            // Number of steps already sent.
            $table->unsignedSmallInteger('steps_sent')->default(0);
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'lead_id']);
            $table->index(['campaign_id', 'status', 'next_send_at']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_leads');
        Schema::dropIfExists('campaign_segment');
        Schema::dropIfExists('campaign_lead_list');
        Schema::dropIfExists('campaign_email_account');
        Schema::dropIfExists('campaign_steps');
        Schema::dropIfExists('campaigns');
    }
};
