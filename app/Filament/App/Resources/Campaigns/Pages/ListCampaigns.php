<?php

namespace App\Filament\App\Resources\Campaigns\Pages;

use App\Filament\App\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignCloner;
use App\Services\Campaigns\TemplateLibrary;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    public function getTabs(): array
    {
        return [
            'campaigns' => Tab::make('Campaigns')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_template', false)),
            'templates' => Tab::make('Templates')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_template', true)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('templateLibrary')
                ->label('Template library')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->visible(fn (): bool => Auth::user()->can('create', Campaign::class))
                ->modalDescription('Start from a proven sequence. Replace the [bracketed] parts with your own words before launching.')
                ->schema([
                    Select::make('template')
                        ->options(fn (): array => collect(app(TemplateLibrary::class)->available(Filament::getTenant()))->map(fn (array $template): string => $template['name'])->all())
                        ->helperText(fn ($get): ?string => TemplateLibrary::TEMPLATES[$get('template')]['description'] ?? null)
                        ->live()
                        ->afterStateUpdated(fn ($state, $set) => $set('name', TemplateLibrary::TEMPLATES[$state]['name'] ?? null))
                        ->required(),
                    TextInput::make('name')->label('Campaign name')->required()->maxLength(150),
                ])
                ->action(function (array $data): void {
                    $campaign = app(TemplateLibrary::class)->create(Filament::getTenant(), (string) $data['template'], (string) $data['name'], Auth::user());
                    $this->redirect(CampaignResource::getUrl('edit', ['record' => $campaign]));
                }),
            Action::make('fromTemplate')
                ->label('Use a template')
                ->icon(Heroicon::OutlinedBookmark)
                ->color('gray')
                ->visible(fn (): bool => Auth::user()->can('create', Campaign::class) && $this->templates() !== [])
                ->schema([
                    Select::make('template_id')
                        ->label('Template')
                        ->options(fn (): array => $this->templates())
                        ->required(),
                    TextInput::make('name')->label('Campaign name')->required()->maxLength(150),
                ])
                ->action(function (array $data): void {
                    $template = Campaign::query()
                        ->where('workspace_id', Filament::getTenant()->getKey())
                        ->where('is_template', true)
                        ->findOrFail($data['template_id']);

                    $campaign = app(CampaignCloner::class)->clone($template, $data['name'], by: Auth::user());
                    $this->redirect(CampaignResource::getUrl('edit', ['record' => $campaign]));
                }),
            CreateAction::make()->label('New campaign'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function templates(): array
    {
        return Campaign::query()
            ->where('workspace_id', Filament::getTenant()->getKey())
            ->where('is_template', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
