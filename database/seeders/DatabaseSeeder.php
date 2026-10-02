<?php

namespace Database\Seeders;

use App\Enums\MailEncryption;
use App\Enums\MailProvider;
use App\Enums\WorkspaceRole;
use App\Models\EmailAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed local development data. Never run in production.
     * Every seeded account uses the password "password".
     */
    public function run(): void
    {
        User::factory()->superAdmin()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
        ]);

        $owner = User::factory()->create([
            'name' => 'Demo Customer',
            'email' => 'demo@example.com',
        ]);

        $teammate = User::factory()->create([
            'name' => 'Demo Teammate',
            'email' => 'teammate@example.com',
        ]);

        $client = User::factory()->create([
            'name' => 'Demo Client',
            'email' => 'client@example.com',
        ]);

        $agency = Workspace::factory()->withMailingAddress()->create([
            'name' => 'Demo Agency',
            'slug' => 'demo-agency',
        ]);
        $agency->addMember($owner, WorkspaceRole::Owner);
        $agency->addMember($teammate, WorkspaceRole::Member);
        $agency->addMember($client, WorkspaceRole::Client);

        // Sends through Mailpit (http://localhost:8025) inside Docker. Mailpit has
        // no IMAP, so "Test connection" reports an IMAP error locally; that's expected.
        EmailAccount::factory()->for($agency)->create([
            'email' => 'outreach@demo-agency.test',
            'from_name' => 'Demo Outreach',
            'provider' => MailProvider::Custom,
            'smtp_host' => 'mailpit',
            'smtp_port' => 1025,
            'smtp_encryption' => MailEncryption::None,
            'smtp_username' => 'outreach@demo-agency.test',
            'smtp_password' => 'not-checked-by-mailpit',
            'imap_host' => 'mailpit',
            'imap_port' => 143,
            'imap_encryption' => MailEncryption::None,
        ]);

        $second = Workspace::factory()->create([
            'name' => 'Second Client Co',
            'slug' => 'second-client-co',
        ]);
        $second->addMember($owner, WorkspaceRole::Owner);
    }
}
