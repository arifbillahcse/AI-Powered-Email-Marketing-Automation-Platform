<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            // Earliest time this mailbox may send again (random gap between emails).
            $table->timestamp('next_send_at')->nullable();
        });

        // One row per email sent (or attempted) by a campaign.
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('step_position');
            // Public, unguessable id used in tracking and unsubscribe links.
            $table->string('token', 40)->unique();
            $table->string('message_id')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('sending');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamp('bounced_at')->nullable();
            $table->timestamps();

            // A step is sent at most once per lead, even if a job runs twice.
            $table->unique(['campaign_lead_id', 'step_position']);
            $table->index(['email_account_id', 'sent_at']);
            $table->index(['campaign_id', 'sent_at']);
            $table->index('message_id');
        });

        // Event log for analytics (Phase 8): sent, open, click, bounce, unsubscribe...
        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('url', 2048)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_id', 'type', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['email_account_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
        Schema::dropIfExists('email_messages');

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn('next_send_at');
        });
    }
};
