<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Enums\InvoiceStatus;
use App\Filament\Admin\Resources\Concerns\SuperAdminOnly;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Services\Billing\Gateways\GatewayManager;
use App\Services\Billing\SubscriptionManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class InvoiceResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationBadge(): ?string
    {
        $open = Invoice::query()->where('status', InvoiceStatus::Open->value)->where('gateway', 'manual')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Bank transfers waiting for confirmation';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('workspace'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('workspace.name')->label('Workspace')->searchable(),
                TextColumn::make('description')->wrap(),
                TextColumn::make('amount')->formatStateUsing(fn (Invoice $record): string => $record->formattedAmount()),
                TextColumn::make('status')->badge(),
                TextColumn::make('gateway')->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—')->placeholder('—'),
                TextColumn::make('created_at')->label('Issued')->date()->sortable(),
                TextColumn::make('paid_at')->label('Paid')->date()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(InvoiceStatus::class),
                SelectFilter::make('gateway')->options(collect(array_keys(GatewayManager::GATEWAYS))->mapWithKeys(fn (string $key): array => [$key => ucfirst($key)])->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('markPaid')
                        ->label('Mark as paid')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->visible(fn (Invoice $record): bool => $record->isOpen())
                        ->modalDescription('For bank transfers and other offline payments. Activates or extends the plan.')
                        ->schema([
                            TextInput::make('reference')->label('Payment reference (bank transaction ID)')->maxLength(100),
                        ])
                        ->action(function (Invoice $record, array $data): void {
                            app(SubscriptionManager::class)->markPaid($record, $record->gateway ?? 'manual', $data['reference'] ?: null);
                            Notification::make()->title("{$record->number} marked as paid")->success()->send();
                        }),
                    Action::make('void')
                        ->label('Void')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->visible(fn (Invoice $record): bool => $record->isOpen())
                        ->requiresConfirmation()
                        ->action(function (Invoice $record): void {
                            $record->forceFill(['status' => InvoiceStatus::Void])->save();
                            Notification::make()->title("{$record->number} voided")->success()->send();
                        }),
                    Action::make('view')
                        ->label('View invoice')
                        ->icon(Heroicon::OutlinedEye)
                        ->url(fn (Invoice $record): string => route('billing.invoice', $record))
                        ->openUrlInNewTab(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }
}
