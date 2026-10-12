<?php

namespace App\Filament\Admin\Resources\Imports\Pages;

use App\Actions\Imports\StoreImportUpload;
use App\Enums\ImportType;
use App\Filament\Admin\Resources\Imports\ImportResource;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Import;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ListRecords;

class ListImports extends ListRecords
{
    protected static string $resource = ImportResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return array_map(fn (ImportType $type): Action => $this->uploadAction($type), ImportType::cases());
    }

    private function uploadAction(ImportType $type): Action
    {
        return Action::make('upload'.ucfirst($type->value))
            ->label('Import '.$type->value)
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', [Import::class, $type]))
            ->modalDescription('Upload a CSV file (in Excel: Save As, CSV UTF-8). You will match its columns and check it before anything is saved.')
            ->schema([
                FileUpload::make('file')
                    ->label('CSV file')
                    ->required()
                    ->disk('local')
                    ->directory('import-uploads')
                    ->visibility('private')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->maxSize((int) config('groibe.imports.max_file_kb'))
                    ->storeFileNamesIn('original_name'),
            ])
            ->action(function (array $data) use ($type): void {
                $import = null;

                $ok = DomainActionRunner::run(function () use ($type, $data, &$import): void {
                    $import = app(StoreImportUpload::class)(auth()->user(), $type, (string) $data['file'], (string) ($data['original_name'] ?? 'import.csv'));
                }, 'File uploaded. Match the columns next.');

                if ($ok && $import instanceof Import) {
                    $this->redirect(ImportResource::getUrl('view', ['record' => $import]));
                }
            });
    }
}
