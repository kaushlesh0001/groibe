<?php

namespace App\Filament\Admin\Resources\Applications\Schemas;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\SkillUser;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Application')
                ->columns(2)
                ->schema([
                    TextEntry::make('openRole.title')->label('Role'),
                    TextEntry::make('status')->badge()->color(fn (ApplicationStatus $state): string => $state->color()),
                    TextEntry::make('created_at')->label('Applied')->dateTime(),
                    TextEntry::make('expected_amount')->label('Expected amount')->money('INR')->placeholder('Not given'),
                    TextEntry::make('motivation')->columnSpanFull(),
                    TextEntry::make('relevant_experience')->placeholder('Not given')->columnSpanFull(),
                    TextEntry::make('availability_note')->placeholder('Not given')->columnSpanFull(),
                    TextEntry::make('admin_note')->label('Internal note')->placeholder('None')->columnSpanFull(),
                ]),
            Section::make('Applicant')
                ->columns(2)
                ->schema([
                    TextEntry::make('user.name')->label('Name'),
                    TextEntry::make('user.email')->label('Email'),
                    TextEntry::make('user.profile.headline')->label('Headline')->placeholder('-'),
                    TextEntry::make('user.profile.hours_per_week')->label('Hours per week')->placeholder('-'),
                    TextEntry::make('skills')
                        ->label('Skills')
                        ->state(fn (Application $record): string => SkillUser::query()->where('user_id', $record->user_id)->with('skill')->get()
                            ->map(fn (SkillUser $row): string => $row->skill?->name.' ('.$row->level->getLabel().')')->implode(', ') ?: 'None added')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
