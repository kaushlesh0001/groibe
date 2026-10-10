<?php

namespace App\Filament\Admin\Resources\Submissions\Tables;

use App\Actions\Submissions\ApproveSubmission;
use App\Actions\Submissions\RequestSubmissionChanges;
use App\Enums\SubmissionStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Submission;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('task.title')->label('Task')->limit(40)->searchable(),
                TextColumn::make('task.milestone.project.code')->label('Project'),
                TextColumn::make('task.milestone.title')->label('Milestone')->limit(30)->toggleable(),
                TextColumn::make('user.name')->label('Contributor'),
                TextColumn::make('version')->label('Version'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SubmissionStatus $state): string => $state->color()),
                TextColumn::make('media_count')->label('Files'),
                TextColumn::make('created_at')->label('Submitted')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->recordActions([
                Action::make('inspect')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Submission $record): string => "{$record->task->title}, version {$record->version}")
                    ->modalContent(fn (Submission $record) => new HtmlString(self::summary($record)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('The task becomes approved and the contributor is told.')
                    ->visible(fn (Submission $record): bool => $record->status === SubmissionStatus::Submitted && auth()->user()?->can('review', $record))
                    ->schema([Textarea::make('note')->label('Note for the contributor (optional)')->rows(3)])
                    ->action(fn (Submission $record, array $data) => DomainActionRunner::run(
                        fn () => app(ApproveSubmission::class)($record, auth()->user(), $data['note'] ?? null),
                        'Submission approved',
                    )),

                Action::make('requestChanges')
                    ->label('Request changes')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Submission $record): bool => $record->status === SubmissionStatus::Submitted && auth()->user()?->can('review', $record))
                    ->schema([Textarea::make('note')->label('What needs to change')->required()->rows(4)])
                    ->action(fn (Submission $record, array $data) => DomainActionRunner::run(
                        fn () => app(RequestSubmissionChanges::class)($record, auth()->user(), $data['note']),
                        'Changes requested',
                    )),
            ]);
    }

    /** The review modal body. Contributor text is escaped; it is shown to admins as plain text. */
    public static function summary(Submission $record): string
    {
        $files = $record->media->map(fn ($media): string => '<li>'.e($media->name.'.'.$media->extension).'</li>')->implode('');

        return '<div class="space-y-3 text-sm">'
            .'<p><strong>Task:</strong> '.e($record->task->description ?? 'No description').'</p>'
            .'<p><strong>Contributor note:</strong> '.e($record->note ?? 'None').'</p>'
            .'<p><strong>Files:</strong></p>'.($files === '' ? '<p>No files.</p>' : "<ul class=\"list-disc pl-5\">{$files}</ul>")
            .($record->qa_note ? '<p><strong>Reviewer note:</strong> '.e($record->qa_note).'</p>' : '')
            .'</div>';
    }
}
