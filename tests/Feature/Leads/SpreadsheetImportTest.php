<?php

use App\Filament\App\Actions\SpreadsheetImportAction;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Models\Lead;
use App\Services\Leads\SpreadsheetConverter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A real .xlsx file with the given rows on its first sheet.
 *
 * @param  list<list<mixed>>  $rows
 */
function xlsxFile(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'leads').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $path;
}

it('converts the first sheet of an Excel file to CSV', function () {
    $path = xlsxFile([
        ['Email', 'First Name', 'Phone', 'Company'],
        ['jane@acme.test', 'Jane', 8801712345678, 'Acme, Inc'],
        ['', '', '', ''],
        ['rahim@softorio.test', 'Rahim', null, 'Softorio'],
    ]);

    $csv = stream_get_contents(app(SpreadsheetConverter::class)->xlsxToCsv($path));

    expect($csv)->toBe("Email,\"First Name\",Phone,Company\njane@acme.test,Jane,8801712345678,\"Acme, Inc\"\nrahim@softorio.test,Rahim,,Softorio\n");
});

it('accepts .xlsx files in the lead import', function () {
    expect(SpreadsheetImportAction::make()->getFileValidationRules())->toContain('extensions:csv,txt,xlsx');
});

it('imports leads from an Excel file', function () {
    $workspace = actingInWorkspace();
    $path = xlsxFile([
        ['Email', 'First Name', 'Company'],
        ['jane@acme.test', 'Jane', 'Acme'],
        ['rahim@softorio.test', 'Rahim', 'Softorio'],
    ]);

    Livewire::test(ListLeads::class)
        ->callAction('import', data: [
            'file' => UploadedFile::fake()->createWithContent('leads.xlsx', file_get_contents($path)),
            'columnMap' => ['email' => 'Email', 'first_name' => 'First Name', 'company' => 'Company'],
        ])
        ->assertHasNoFormErrors();

    expect(Lead::query()->where('workspace_id', $workspace->id)->orderBy('email')->pluck('first_name', 'email')->all())
        ->toBe(['jane@acme.test' => 'Jane', 'rahim@softorio.test' => 'Rahim']);
});
