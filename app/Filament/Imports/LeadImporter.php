<?php

namespace App\Filament\Imports;

use App\Enums\LeadActivityType;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Tag;
use App\Models\User;
use App\Support\CustomFieldKey;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Queued CSV import of leads. Runs in chunks of 100 rows, so each job stays
 * well inside shared-hosting time limits.
 *
 * Options (set by the import action and its form):
 * - workspace_id: injected server-side, never from the form
 * - list_id / new_list: add every imported lead to this list
 * - tags: tag every imported lead
 * - update_existing: overwrite fields of leads that already exist
 * - keep_extra_columns: store unmapped CSV columns as custom fields
 */
class LeadImporter extends Importer
{
    protected static ?string $model = Lead::class;

    protected ?int $checkedWorkspaceId = null;

    protected ?int $resolvedListId = null;

    protected bool $listResolved = false;

    /**
     * @var list<int>|null
     */
    protected ?array $resolvedTagIds = null;

    public static function getColumns(): array
    {
        $text = fn (string $name, string $label, array $guess, int $max = 255): ImportColumn => ImportColumn::make($name)
            ->label($label)
            ->guess($guess)
            ->rules(['nullable', 'string', "max:{$max}"])
            ->castStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
            ->ignoreBlankState();

        return [
            ImportColumn::make('email')
                ->label('Email')
                ->requiredMapping()
                ->guess(['email', 'email address', 'e-mail', 'mail', 'work email'])
                ->castStateUsing(fn (?string $state): string => mb_strtolower(trim((string) $state)))
                ->rules(['required', 'email:filter', 'max:255'])
                ->example('jane@acme.com'),
            $text('first_name', 'First name', ['first name', 'firstname', 'first', 'given name'], 100)->example('Jane'),
            $text('last_name', 'Last name', ['last name', 'lastname', 'last', 'surname', 'family name'], 100)->example('Doe'),
            $text('company', 'Company', ['company', 'company name', 'organization', 'organisation', 'account'])->example('Acme Inc'),
            $text('title', 'Job title', ['title', 'job title', 'position', 'role'])->example('Head of Marketing'),
            $text('phone', 'Phone', ['phone', 'phone number', 'mobile', 'telephone'], 50),
            $text('website', 'Website', ['website', 'url', 'domain', 'company website']),
            $text('linkedin_url', 'LinkedIn URL', ['linkedin', 'linkedin url', 'linkedin profile', 'person linkedin url']),
            $text('city', 'City', ['city', 'town']),
            $text('country', 'Country', ['country']),
            $text('timezone', 'Time zone', ['timezone', 'time zone', 'tz'], 64),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('list_id')
                ->label('Add to existing list')
                ->options(fn (): array => LeadList::query()
                    ->where('workspace_id', Filament::getTenant()?->getKey())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable(),
            TextInput::make('new_list')
                ->label('…or create a new list')
                ->placeholder('e.g. SaaS founders – Oct')
                ->maxLength(100),
            TagsInput::make('tags')
                ->label('Tag imported leads'),
            Toggle::make('update_existing')
                ->label('Update leads that already exist')
                ->helperText('Matched by email. Off: existing leads are left as they are (but still added to the list and tags).')
                ->default(true),
            Toggle::make('keep_extra_columns')
                ->label('Save other columns as custom fields')
                ->helperText('Unmapped columns become {{variables}}, e.g. "Company Size" → {{company_size}}.')
                ->default(true),
        ];
    }

    public function resolveRecord(): ?Lead
    {
        $workspaceId = $this->workspaceId();

        $lead = Lead::query()
            ->where('workspace_id', $workspaceId)
            ->where('email', $this->data['email'] ?? null)
            ->first();

        if ($lead) {
            return $lead;
        }

        $lead = new Lead;
        $lead->workspace_id = $workspaceId;
        $lead->source = 'import';

        return $lead;
    }

    public function fillRecord(): void
    {
        /** @var Lead $lead */
        $lead = $this->record;

        if ($lead->exists && ! ($this->options['update_existing'] ?? true)) {
            return;
        }

        parent::fillRecord();

        if ($this->options['keep_extra_columns'] ?? true) {
            $extra = $this->extraColumns();

            if ($extra !== []) {
                $lead->custom_fields = array_merge($lead->custom_fields ?? [], $extra);
            }
        }
    }

    /**
     * Save in a savepoint: the import job wraps each chunk in one transaction,
     * and on PostgreSQL a failed insert (e.g. the same email arriving from a
     * parallel chunk) would otherwise abort every remaining row in the chunk.
     */
    public function saveRecord(): void
    {
        /** @var Lead $lead */
        $lead = $this->record;
        $isNew = ! $lead->exists;

        try {
            DB::transaction(fn () => $lead->save());
        } catch (UniqueConstraintViolationException) {
            $existing = Lead::query()
                ->where('workspace_id', $lead->workspace_id)
                ->where('email', $lead->email)
                ->firstOrFail();

            if ($this->options['update_existing'] ?? true) {
                $existing->fill(array_filter($lead->only(Lead::FIELDS), fn ($value): bool => filled($value)))->save();
            }

            $this->record = $lead = $existing;
            $isNew = false;
        }

        $this->attachListAndTags($lead);

        $lead->logActivity(
            LeadActivityType::Imported,
            ($isNew ? 'Imported from ' : 'Updated by import of ').$this->import->file_name,
            ['import_id' => $this->import->getKey()],
        );
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your lead import finished: '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed; download them from this notification to fix and re-import.';
        }

        return $body;
    }

    public function getJobQueue(): ?string
    {
        return 'imports';
    }

    /**
     * The workspace comes from options set server-side. Re-check that the
     * importing user can still write to it.
     */
    protected function workspaceId(): int
    {
        if ($this->checkedWorkspaceId !== null) {
            return $this->checkedWorkspaceId;
        }

        $workspaceId = (int) ($this->options['workspace_id'] ?? 0);

        /** @var User $user */
        $user = $this->import->user;

        if (! $workspaceId || ! $user->roleIn($workspaceId)?->canWrite()) {
            throw new RowImportFailedException('You no longer have permission to import leads into this workspace.');
        }

        return $this->checkedWorkspaceId = $workspaceId;
    }

    /**
     * CSV columns that weren't mapped to a lead field.
     *
     * @return array<string, string>
     */
    protected function extraColumns(): array
    {
        $mapped = array_filter(array_values($this->columnMap));
        $extra = [];

        foreach ($this->originalData as $header => $value) {
            if (in_array($header, $mapped, true) || blank($value)) {
                continue;
            }

            $key = CustomFieldKey::normalize((string) $header);

            if ($key !== '' && ! in_array($key, Lead::FIELDS, true)) {
                $extra[$key] = mb_substr(trim((string) $value), 0, 1000);
            }
        }

        return $extra;
    }

    protected function attachListAndTags(Lead $lead): void
    {
        if ($listId = $this->listId($lead->workspace_id)) {
            DB::table('lead_list_lead')->insertOrIgnore([
                'lead_list_id' => $listId,
                'lead_id' => $lead->getKey(),
                'created_at' => now(),
            ]);
        }

        $this->resolvedTagIds ??= Tag::idsForNames($lead->workspace_id, (array) ($this->options['tags'] ?? []));

        if ($this->resolvedTagIds !== []) {
            DB::table('lead_tag')->insertOrIgnore(array_map(
                fn (int $tagId): array => ['tag_id' => $tagId, 'lead_id' => $lead->getKey()],
                $this->resolvedTagIds,
            ));
        }
    }

    protected function listId(int $workspaceId): ?int
    {
        if ($this->listResolved) {
            return $this->resolvedListId;
        }

        $this->listResolved = true;

        if (filled($name = trim((string) ($this->options['new_list'] ?? '')))) {
            $list = LeadList::query()->where('workspace_id', $workspaceId)->where('name', $name)->first();

            if (! $list) {
                $list = new LeadList(['name' => $name]);
                $list->workspace_id = $workspaceId;

                try {
                    DB::transaction(fn () => $list->save());
                } catch (UniqueConstraintViolationException) {
                    // Another chunk created it a moment ago.
                    $list = LeadList::query()->where('workspace_id', $workspaceId)->where('name', $name)->firstOrFail();
                }
            }

            return $this->resolvedListId = $list->getKey();
        }

        if (filled($this->options['list_id'] ?? null)) {
            // Only lists that belong to the import's workspace.
            return $this->resolvedListId = LeadList::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($this->options['list_id'])
                ->value('id');
        }

        return null;
    }
}
