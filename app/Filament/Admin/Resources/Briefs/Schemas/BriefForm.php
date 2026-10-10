<?php

namespace App\Filament\Admin\Resources\Briefs\Schemas;

use App\Enums\BriefStatus;
use App\Enums\Confidentiality;
use App\Enums\UserRole;
use App\Models\Brief;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class BriefForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client submission')
                    ->description('Written by the client. Read-only here.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('organization_name')->label('Organization')->disabled()->dehydrated(false),
                        TextInput::make('status')->formatStateUsing(fn ($state) => BriefStatus::tryFrom((string) $state)?->getLabel())->disabled()->dehydrated(false),
                        TextInput::make('title')->disabled()->dehydrated(false)->columnSpanFull(),
                        Textarea::make('problem')->rows(5)->disabled()->dehydrated(false)->columnSpanFull(),
                        Textarea::make('desired_outcome')->rows(4)->disabled()->dehydrated(false)->columnSpanFull(),
                        TextInput::make('project_type_name')->label('Type of work')->disabled()->dehydrated(false),
                        TextInput::make('confidentiality')->formatStateUsing(fn ($state) => Confidentiality::tryFrom((string) $state)?->getLabel())->disabled()->dehydrated(false),
                        TextInput::make('budget_band')
                            ->formatStateUsing(fn (?string $state) => config('groibe.briefs.budget_bands.'.$state, $state))
                            ->disabled()->dehydrated(false),
                        TextInput::make('timeline_band')
                            ->formatStateUsing(fn (?string $state) => config('groibe.briefs.timeline_bands.'.$state, $state))
                            ->disabled()->dehydrated(false),
                        DatePicker::make('target_start')->disabled()->dehydrated(false),
                        DatePicker::make('deadline')->disabled()->dehydrated(false),
                        Textarea::make('needs_info_note')->label('Last question sent to client')->rows(2)->disabled()->dehydrated(false)->columnSpanFull(),
                        Html::make(fn (?Model $record) => $record instanceof Brief ? view('filament.admin.media-list', [
                            'files' => $record->getMedia(Brief::FILES)->map(fn ($media): array => [
                                'name' => $media->name.'.'.$media->extension,
                                'size' => $media->human_readable_size,
                                'url' => URL::temporarySignedRoute('files.show', now()->addMinutes((int) config('groibe.uploads.signed_url_minutes')), ['media' => $media->uuid]),
                            ]),
                        ]) : '')->columnSpanFull(),
                    ]),
                Section::make('Internal')
                    ->description('Never shown to the client.')
                    ->schema([
                        Select::make('assigned_admin_id')
                            ->label('Assigned admin')
                            ->options(fn (): array => User::query()
                                ->where('role', UserRole::Admin->value)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                        Textarea::make('internal_notes')->rows(5),
                    ]),
            ]);
    }
}
