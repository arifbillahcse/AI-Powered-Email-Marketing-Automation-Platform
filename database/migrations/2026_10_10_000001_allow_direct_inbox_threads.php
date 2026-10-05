<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A conversation can now start with a one-off email to a lead, with
        // no campaign behind it.
        Schema::table('inbox_threads', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_lead_id')->nullable()->change();
            $table->unsignedBigInteger('campaign_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('inbox_threads')->whereNull('campaign_lead_id')->delete();

        Schema::table('inbox_threads', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_lead_id')->nullable(false)->change();
            $table->unsignedBigInteger('campaign_id')->nullable(false)->change();
        });
    }
};
