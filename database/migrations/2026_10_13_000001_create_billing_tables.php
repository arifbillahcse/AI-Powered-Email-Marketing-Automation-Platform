<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('price_monthly')->default(0); // minor units (cents/paisa)
            $table->unsignedInteger('price_yearly')->default(0);
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->jsonb('limits')->nullable(); // mailboxes, leads, emails_per_month, team_members (null = unlimited)
            $table->jsonb('modules')->nullable(); // module keys included in the plan
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // new workspaces start a trial on it
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20); // trialing | active | past_due | expired
            $table->string('interval', 10)->default('monthly'); // monthly | yearly
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->string('status', 10)->default('open'); // open | paid | void
            $table->string('interval', 10)->default('monthly');
            $table->string('description');
            $table->string('currency', 3);
            $table->unsignedInteger('amount'); // minor units
            $table->boolean('is_renewal')->default(false);
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_reference')->nullable(); // checkout session / tran_id / paymentID
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'gateway_reference']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('suspended_at'));
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn(['suspended_at', 'suspension_reason']));
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
