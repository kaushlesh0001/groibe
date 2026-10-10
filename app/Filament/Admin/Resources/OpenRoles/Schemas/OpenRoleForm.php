<?php

namespace App\Filament\Admin\Resources\OpenRoles\Schemas;

use App\Enums\CompensationType;
use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Enums\WorkMode;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Skill;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OpenRoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                        TextInput::make('status')
                            ->formatStateUsing(fn ($state) => $state instanceof OpenRoleStatus ? $state->getLabel() : OpenRoleStatus::tryFrom((string) $state)?->getLabel())
                            ->disabled()->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation !== 'create'),
                        TextInput::make('slug')->disabled()->dehydrated(false)->visible(fn (string $operation): bool => $operation !== 'create'),
                        Textarea::make('summary')->rows(2)->maxLength(500)->helperText('Shown in lists and search. Plain text.')->columnSpanFull(),
                        Textarea::make('description')->rows(8)->maxLength(10000)->helperText('Plain text. Never include the client name on a public role.')->columnSpanFull(),
                        Repeater::make('tasks_list')->label('What the contributor will do')->simple(TextInput::make('task')->required()->maxLength(255))->defaultItems(0)->addActionLabel('Add task')->columnSpanFull(),
                        Repeater::make('learning_outcomes')->label('What they will take away')->simple(TextInput::make('outcome')->required()->maxLength(255))->defaultItems(0)->addActionLabel('Add outcome')->columnSpanFull(),
                        Select::make('skill_ids')
                            ->label('Skills')
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => Skill::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->columnSpanFull(),
                    ]),
                Section::make('Terms')
                    ->columns(2)
                    ->schema([
                        Select::make('work_mode')->options(WorkMode::class)->required()->default(WorkMode::Remote->value),
                        Select::make('project_type_id')
                            ->label('Type of work')
                            ->options(fn (): array => ProjectType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        TextInput::make('est_hours_min')->label('Hours per week, from')->numeric()->minValue(1)->maxValue(80),
                        TextInput::make('est_hours_max')->label('Hours per week, to')->numeric()->minValue(1)->maxValue(80)->gte('est_hours_min'),
                        TextInput::make('duration_label')->label('Duration')->placeholder('4 weeks')->maxLength(60),
                        TextInput::make('positions_needed')->numeric()->required()->minValue(1)->maxValue(100)->default(1),
                        Select::make('compensation_type')->options(CompensationType::class)->required()->default(CompensationType::Paid->value),
                        TextInput::make('compensation_note')->maxLength(255),
                    ]),
                Section::make('Publishing')
                    ->columns(2)
                    ->schema([
                        Select::make('visibility')
                            ->options(OpenRoleVisibility::class)
                            ->required()
                            ->default(OpenRoleVisibility::MembersOnly->value)
                            ->helperText('Invite only: add contributors on the Invites tab after saving. Members only: any active contributor. Public: anyone, but applying needs a login.'),
                        DatePicker::make('deadline')->helperText('Applications stop after this day.'),
                        Select::make('project_id')
                            ->label('Linked project')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Project::query()
                                ->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                                ->limit(25)->pluck('title', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Project::query()->whereKey($value)->value('title'))
                            ->helperText('An accepted offer seats the contributor on this project, which must be staffing or active. Leave empty for a community role.'),
                        Toggle::make('is_featured')->label('Featured'),
                    ]),
            ]);
    }
}
