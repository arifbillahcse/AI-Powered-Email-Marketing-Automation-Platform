<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every metric is a timestamp on the sent email, set by its event, so
        // analytics are simple counts over one table.
        Schema::table('email_messages', function (Blueprint $table) {
            $table->timestamp('unsubscribed_at')->nullable();
            $table->index(['workspace_id', 'sent_at']);
        });

        // Backfill from the events recorded so far.
        DB::table('email_messages')
            ->whereExists(fn (Builder $query) => $query->select(DB::raw(1))
                ->from('email_events')
                ->whereColumn('email_events.email_message_id', 'email_messages.id')
                ->where('email_events.type', 'unsubscribe'))
            ->update([
                'unsubscribed_at' => DB::raw("(select min(email_events.created_at) from email_events where email_events.email_message_id = email_messages.id and email_events.type = 'unsubscribe')"),
            ]);
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'sent_at']);
            $table->dropColumn('unsubscribed_at');
        });
    }
};
