<?php

use App\Filament\App\Actions\SpreadsheetImportAction;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Services\Leads\SpreadsheetConverter;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
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
    // The queued import itself is covered by LeadImporterTest; here the
    // .xlsx must be read, mapped and counted like a CSV.
    Bus::fake();
    actingInWorkspace();
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
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Import::sole())
        ->file_name->toBe('leads.xlsx')
        ->total_rows->toBe(2);
});
