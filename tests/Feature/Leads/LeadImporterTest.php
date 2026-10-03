<?php

use App\Enums\WorkspaceRole;
use App\Filament\Imports\LeadImporter;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->withMember($this->user, WorkspaceRole::Member)->create();
});

/**
 * Run rows through the importer exactly like Filament's import job does.
 */
function importRows(User $user, array $rows, array $options, ?array $columnMap = null): Import
{
    $import = new Import;
    $import->forceFill([
        'user_id' => $user->id,
        'file_name' => 'leads.csv',
        'file_path' => 'imports/leads.csv',
        'importer' => LeadImporter::class,
        'total_rows' => count($rows),
    ])->save();

    $columnMap ??= ['email' => 'Email', 'first_name' => 'First Name', 'company' => 'Company'];
    $importer = new LeadImporter($import, $columnMap, $options);

    foreach ($rows as $row) {
        $importer($row);
    }

    return $import;
}

it('imports leads with extra columns as custom fields, list and tags', function () {
    importRows($this->user, [
        ['Email' => 'Jane@Acme.com', 'First Name' => 'Jane', 'Company' => 'Acme', 'Company Size' => '11-50'],
        ['Email' => 'bob@globex.com', 'First Name' => 'Bob', 'Company' => '', 'Company Size' => ''],
    ], [
        'workspace_id' => $this->workspace->id,
        'new_list' => 'October import',
        'tags' => ['Imported', 'cold'],
        'update_existing' => true,
        'keep_extra_columns' => true,
    ]);

    $jane = Lead::where('email', 'jane@acme.com')->sole();
    $list = LeadList::where('name', 'October import')->sole();

    expect($jane->workspace_id)->toBe($this->workspace->id)
        ->and($jane->source)->toBe('import')
        ->and($jane->custom_fields)->toBe(['company_size' => '11-50'])
        ->and($jane->tags()->pluck('name')->sort()->values()->all())->toBe(['cold', 'imported'])
        ->and($list->workspace_id)->toBe($this->workspace->id)
        ->and($list->leads()->count())->toBe(2)
        ->and($jane->activities()->first()->description)->toBe('Imported from leads.csv');
});

it('updates existing leads by email without blanking fields', function () {
    $existing = Lead::factory()->for($this->workspace)->create(['email' => 'jane@acme.com', 'first_name' => 'Old', 'company' => 'Acme']);

    importRows($this->user, [['Email' => 'JANE@acme.com', 'First Name' => 'Jane', 'Company' => '']], [
        'workspace_id' => $this->workspace->id,
        'update_existing' => true,
    ]);

    expect(Lead::count())->toBe(1)
        ->and($existing->refresh()->first_name)->toBe('Jane')
        ->and($existing->company)->toBe('Acme');
});

it('can leave existing leads untouched', function () {
    $existing = Lead::factory()->for($this->workspace)->create(['email' => 'jane@acme.com', 'first_name' => 'Old']);

    importRows($this->user, [['Email' => 'jane@acme.com', 'First Name' => 'New', 'Company' => 'X']], [
        'workspace_id' => $this->workspace->id,
        'update_existing' => false,
    ]);

    expect($existing->refresh()->first_name)->toBe('Old');
});

it('rejects invalid emails', function () {
    importRows($this->user, [['Email' => 'not-an-email', 'First Name' => 'X', 'Company' => 'Y']], [
        'workspace_id' => $this->workspace->id,
    ]);
})->throws(ValidationException::class);

it('never imports into a workspace the user cannot write to', function () {
    $other = Workspace::factory()->create();

    importRows($this->user, [['Email' => 'jane@acme.com', 'First Name' => 'J', 'Company' => 'A']], [
        'workspace_id' => $other->id,
    ]);
})->throws(RowImportFailedException::class);

it('ignores a list from another workspace', function () {
    $foreignList = LeadList::factory()->create();

    importRows($this->user, [['Email' => 'jane@acme.com', 'First Name' => 'J', 'Company' => 'A']], [
        'workspace_id' => $this->workspace->id,
        'list_id' => $foreignList->id,
    ]);

    expect($foreignList->leads()->count())->toBe(0);
});
