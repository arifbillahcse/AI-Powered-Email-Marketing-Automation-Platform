<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL can't compare `json` values, so SELECT DISTINCT over a table
 * with a json column fails (Filament's relationship selects do exactly
 * that). `jsonb` can be compared. MySQL has a single JSON type, so this
 * migration does nothing there. New JSON columns use ->jsonb() directly.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    protected array $columns = [
        'email_accounts' => ['send_days'],
        'sending_domains' => ['checks'],
        'leads' => ['custom_fields'],
        'segments' => ['rules'],
        'lead_activities' => ['properties'],
        'campaigns' => ['send_days'],
    ];

    public function up(): void
    {
        $this->convert('jsonb');
    }

    public function down(): void
    {
        $this->convert('json');
    }

    protected function convert(string $type): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE {$type} USING \"{$column}\"::{$type}");
            }
        }
    }
};
