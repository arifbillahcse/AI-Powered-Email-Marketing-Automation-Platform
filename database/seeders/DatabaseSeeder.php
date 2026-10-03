<?php

namespace Database\Seeders;

use App\Enums\LeadStatus;
use App\Enums\MailEncryption;
use App\Enums\MailProvider;
use App\Enums\SuppressionReason;
use App\Enums\WorkspaceRole;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Segment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Leads\SuppressionList;
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

        // Leads, lists, tags, a segment and suppressions for Phase 3.
        $founders = LeadList::factory()->for($agency)->create(['name' => 'SaaS founders']);
        $agencies = LeadList::factory()->for($agency)->create(['name' => 'Marketing agencies']);

        Lead::factory()->for($agency)->count(25)->create()->each(function (Lead $lead, int $i) use ($founders, $agencies): void {
            $lead->lists()->attach($i % 2 ? $founders : $agencies);
            $lead->syncTagNames($i % 3 ? ['cold'] : ['hot', 'priority']);

            if ($i % 7 === 0) {
                $lead->update(['status' => LeadStatus::Interested, 'custom_fields' => ['company_size' => '11-50']]);
            }
        });

        Segment::factory()->for($agency)->create([
            'name' => 'Hot founders',
            'rules' => [
                ['field' => 'list', 'operator' => 'in', 'value' => (string) $founders->id],
                ['field' => 'tag', 'operator' => 'has', 'value' => 'hot'],
            ],
        ]);

        $suppressions = app(SuppressionList::class);
        $suppressions->add($agency->id, 'competitor.com', SuppressionReason::Manual);
        $suppressions->add($agency->id, 'unsubscribed@example.org', SuppressionReason::Unsubscribed);

        $second = Workspace::factory()->create([
            'name' => 'Second Client Co',
            'slug' => 'second-client-co',
        ]);
        $second->addMember($owner, WorkspaceRole::Owner);
    }
}
