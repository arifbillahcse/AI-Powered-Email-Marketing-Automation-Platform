<?php

namespace App\Filament\App\Resources\InboxThreads\Pages;

use App\Filament\App\Resources\InboxThreads\InboxThreadResource;
use Filament\Resources\Pages\ListRecords;

class ListInboxThreads extends ListRecords
{
    protected static string $resource = InboxThreadResource::class;

    protected static ?string $title = 'Unibox';
}
