<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where IMAP polling left off for each mailbox.
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('imap_uid_validity')->nullable();
            $table->unsignedBigInteger('imap_last_uid')->nullable();
            $table->timestamp('imap_synced_at')->nullable();
            $table->text('imap_error')->nullable();
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->timestamp('replied_at')->nullable();
        });

        Schema::table('campaign_leads', function (Blueprint $table) {
            $table->timestamp('replied_at')->nullable();
        });

        // One conversation per lead per campaign: the Unibox rows.
        Schema::create('inbox_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('snippet')->nullable();
            $table->boolean('unread')->default(true);
            $table->boolean('auto_reply')->default(false); // the latest inbound was an out-of-office
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'last_message_at']);
            $table->index(['workspace_id', 'unread']);
        });

        // Replies received and replies sent by hand from the Unibox.
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained()->nullOnDelete(); // the campaign email replied to
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who sent an outbound reply
            $table->string('direction', 10); // inbound | outbound
            $table->string('status', 20)->default('received'); // received | sending | sent | failed
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('to_email');
            $table->string('subject')->nullable();
            $table->longText('body'); // plain text; HTML is converted, never rendered
            $table->boolean('auto_reply')->default(false);
            $table->text('error')->nullable();
            $table->unsignedBigInteger('imap_uid')->nullable();
            $table->timestamp('sent_at')->nullable(); // Date header (inbound) or send time (outbound)
            $table->timestamps();

            $table->unique(['email_account_id', 'message_id']);
            $table->index(['inbox_thread_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('inbox_threads');

        Schema::table('campaign_leads', fn (Blueprint $table) => $table->dropColumn('replied_at'));
        Schema::table('email_messages', fn (Blueprint $table) => $table->dropColumn('replied_at'));
        Schema::table('email_accounts', fn (Blueprint $table) => $table->dropColumn(['imap_uid_validity', 'imap_last_uid', 'imap_synced_at', 'imap_error']));
    }
};
