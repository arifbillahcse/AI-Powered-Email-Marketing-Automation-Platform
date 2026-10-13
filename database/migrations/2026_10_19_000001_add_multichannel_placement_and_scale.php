<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Multichannel sequences: a step is an email or a manual task
        // (LinkedIn, call, other) that waits until someone completes it.
        Schema::table('campaign_steps', function (Blueprint $table) {
            $table->string('type', 20)->default('email');
            // Manual steps may have no notes.
            $table->text('body')->nullable()->change();
        });

        Schema::create('campaign_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_step_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('step_position');
            $table->string('type', 20);
            $table->string('title', 255);
            $table->text('instructions')->nullable();
            $table->string('status', 10)->default('open'); // open | done | skipped
            $table->timestamp('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_lead_id', 'step_position']);
            $table->index(['workspace_id', 'status', 'due_at']);
        });

        // Inbox placement tests: one email to each seed mailbox, then a
        // check of where it landed.
        Schema::create('placement_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 255);
            $table->text('body');
            $table->string('status', 10)->default('sending'); // sending | checking | done | failed
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('placement_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seed_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('token', 40)->unique();
            $table->string('status', 10)->default('pending'); // pending | inbox | spam | missing | failed
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['seed_id', 'status']);
        });

        // High-volume workspaces send from their own queue and workers.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('dedicated_sending')->default(false);
        });

        // Event archiving walks old events by date.
        Schema::table('email_events', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_events', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('dedicated_sending');
        });

        Schema::dropIfExists('placement_results');
        Schema::dropIfExists('placement_tests');
        Schema::dropIfExists('campaign_tasks');

        Schema::table('campaign_steps', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
