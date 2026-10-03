<?php

namespace App\Filament\App\Resources\Campaigns\Pages;

use App\Filament\App\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignCloner;
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
