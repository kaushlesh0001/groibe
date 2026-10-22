<?php

namespace App\Filament\Admin\Resources\Projects\RelationManagers;

use App\Actions\Files\LogFileDownload;
use App\Actions\Sows\CreateSow;
use App\Actions\Sows\SendSow;
use App\Actions\Sows\UpdateSow;
use App\Enums\SowStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Project;
use App\Models\Sow;
use App\Services\Sows\SowPdf;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** SOW versions for a project (spec 6.3, 13). Everything goes through the Sow Actions. */
class SowsRelationManager extends RelationManager
{
    protected static string $relationship = 'sows';

    protected static ?string $title = 'Statements of Work';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SowStatus $state): string => $state->color()),
                TextColumn::make('title')->limit(40),
                TextColumn::make('total_value')->money('INR'),
                TextColumn::make('sent_at')->dateTime()->placeholder('Not sent'),
                TextColumn::make('accepted_at')->dateTime()->placeholder('Not accepted'),
                TextColumn::make('accepter.name')->label('Accepted by')->placeholder('-'),
            ])
            ->defaultSort('version', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('accepter:id,name'))
            ->headerActions([
                Action::make('newSow')
                    ->label('New SOW draft')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('create', Sow::class))
                    ->schema([
                        TextInput::make('total_value')->numeric()->minValue(0)->prefix('INR')->default(fn () => $this->ownerProject()->contract_value),
                        Textarea::make('payment_terms')->rows(3),
                    ])
                    ->action(fn (array $data) => DomainActionRunner::run(
                        fn () => app(CreateSow::class)($this->ownerProject(), auth()->user(), array_filter($data, fn ($v) => filled($v))),
                        'SOW draft created',
                    )),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Sow $record): string => 'SOW version '.$record->version)
                    ->modalContent(fn (Sow $record) => new HtmlString('<div class="prose max-w-none">'.$record->body_html.'</div>'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('editSow')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (Sow $record): bool => in_array($record->status, [SowStatus::Draft, SowStatus::Sent], true)
                        && auth()->user()?->can('update', $record))
                    ->modalDescription(fn (Sow $record): ?string => $record->status === SowStatus::Sent
                        ? 'This version was already sent. Saving creates a new draft version and supersedes this one.'
                        : null)
                    ->fillForm(fn (Sow $record): array => $record->only(['title', 'body_html', 'total_value', 'payment_terms']))
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255),
                        RichEditor::make('body_html')->label('Text')->required(),
                        TextInput::make('total_value')->numeric()->minValue(0)->prefix('INR'),
                        Textarea::make('payment_terms')->rows(3),
                        Toggle::make('rerender')->label('Rebuild the text from the SOW template (discards manual edits to the text)'),
                    ])
                    ->action(fn (Sow $record, array $data) => DomainActionRunner::run(
                        fn () => app(UpdateSow::class)($record, auth()->user(), $data),
                        'SOW saved',
                    )),

                Action::make('sendSow')
                    ->label('Send to client')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('The client owner and managers are notified and can review and accept it.')
                    ->visible(fn (Sow $record): bool => $record->status === SowStatus::Draft && auth()->user()?->can('send', $record))
                    ->action(fn (Sow $record) => DomainActionRunner::run(fn () => app(SendSow::class)($record, auth()->user()), 'SOW sent to the client')),

                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Sow $record): bool => (bool) auth()->user()?->can('view', $record))
                    ->action(function (Sow $record) {
                        $pdf = app(SowPdf::class);
                        app(LogFileDownload::class)->sow($record, auth()->user(), 'downloaded');

                        return response()->streamDownload(function () use ($pdf, $record): void {
                            echo $pdf->render($record);
                        }, $pdf->filename($record), ['Content-Type' => 'application/pdf']);
                    }),
            ]);
    }

    private function ownerProject(): Project
    {
        /** @var Project */
        return $this->getOwnerRecord();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewAny', Sow::class);
    }
}
