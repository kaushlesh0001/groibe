<?php

namespace App\Filament\Admin\Resources\Contributors\Schemas;

use App\Enums\OnboardingState;
use App\Models\Profile;
use App\Models\SkillUser;
use App\Services\Contributors\ProfileCompleteness;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContributorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Review')
                ->columns(2)
                ->schema([
                    TextEntry::make('user.name')->label('Name'),
                    TextEntry::make('user.email')->label('Email'),
                    TextEntry::make('onboarding_state')
                        ->label('State')
                        ->badge()
                        ->color(fn (OnboardingState $state): string => $state->color()),
                    TextEntry::make('user.status')->label('Account status')->badge(),
                    TextEntry::make('email_verified')
                        ->label('Email verified')
                        ->state(fn (Profile $record): string => $record->user->email_verified_at !== null ? 'Yes' : 'No'),
                    TextEntry::make('member_agreement')
                        ->label('Member Agreement accepted')
                        ->state(fn (Profile $record): string => app(ProfileCompleteness::class)->hasAcceptedMemberAgreement($record->user) ? 'Yes, current version' : 'No'),
                    TextEntry::make('is_public')->label('Public profile')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                    TextEntry::make('created_at')->label('Registered')->dateTime(),
                ]),
            Section::make('Profile')
                ->columns(2)
                ->schema([
                    TextEntry::make('headline'),
                    TextEntry::make('city'),
                    TextEntry::make('state'),
                    TextEntry::make('country'),
                    TextEntry::make('phone'),
                    TextEntry::make('experience_years')->label('Years of experience'),
                    TextEntry::make('availability')->badge(),
                    TextEntry::make('hours_per_week')->label('Hours per week'),
                    TextEntry::make('linkedin_url')->label('LinkedIn'),
                    TextEntry::make('portfolio_url')->label('Portfolio'),
                    TextEntry::make('bio')->columnSpanFull(),
                ]),
            Section::make('Skills')
                ->schema([
                    TextEntry::make('skills_summary')
                        ->hiddenLabel()
                        ->state(fn (Profile $record): string => self::skillsSummary($record)),
                ]),
        ]);
    }

    private static function skillsSummary(Profile $record): string
    {
        $skills = SkillUser::query()->where('user_id', $record->user_id)->with('skill')->get()
            ->sortBy(fn (SkillUser $row): string => (string) $row->skill?->name);

        if ($skills->isEmpty()) {
            return 'No skills added yet.';
        }

        return $skills->map(fn (SkillUser $row): string => sprintf(
            '%s (%s)%s',
            $row->skill?->name,
            $row->level->getLabel(),
            $row->is_verified ? ', verified' : '',
        ))->implode('; ');
    }
}
