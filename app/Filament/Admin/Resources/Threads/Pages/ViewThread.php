<?php

namespace App\Filament\Admin\Resources\Threads\Pages;

use App\Filament\Admin\Resources\Threads\ThreadResource;
use App\Models\Thread;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** Reads and answers one conversation with the shared Conversation component. The record is resolved through the resource query, so it is only found when the policy lets this admin see it. */
class ViewThread extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ThreadResource::class;

    protected string $view = 'filament.admin.threads.view-thread';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('view', $this->record), 404);
    }

    public function getTitle(): string
    {
        return $this->record instanceof Thread ? $this->record->subject : 'Conversation';
    }
}
