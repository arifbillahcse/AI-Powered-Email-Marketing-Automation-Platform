<?php

namespace App\Filament\App\Actions;

use App\Services\Leads\SpreadsheetConverter;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Filament's CSV import, also accepting Excel (.xlsx) files: the first
 * sheet is converted to CSV whenever the upload is read, so mapping,
 * validation and the queued import work exactly as for CSV.
 */
class SpreadsheetImportAction extends ImportAction
{
    public const XLSX_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->schema;

        $this->schema(function (ImportAction $action) use ($schema): array {
            $components = $action->evaluate($schema) ?? [];

            foreach ($components as $component) {
                if ($component instanceof FileUpload && $component->getName() === 'file') {
                    $component
                        ->acceptedFileTypes([...($component->getAcceptedFileTypes() ?? []), ...self::XLSX_MIME_TYPES])
                        ->helperText('CSV or Excel (.xlsx). For Excel files, the first sheet is imported.');
                }
            }

            return $components;
        });
    }

    public function getFileValidationRules(): array
    {
        return array_map(
            fn ($rule) => $rule === 'extensions:csv,txt' ? 'extensions:csv,txt,xlsx' : $rule,
            parent::getFileValidationRules(),
        );
    }

    /**
     * @return resource|false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return parent::getUploadedFileStream($file);
        }

        // OpenSpout needs a local file path.
        $path = $file->getRealPath();

        if (! is_string($path) || ! is_file($path)) {
            $path = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($path, $file->readStream());
        }

        return app(SpreadsheetConverter::class)->xlsxToCsv($path, ($this->getMaxRows() ?? 200_000) + 1);
    }
}
