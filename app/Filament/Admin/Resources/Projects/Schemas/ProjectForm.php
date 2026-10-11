<?php

namespace App\Filament\Admin\Resources\Projects\Schemas;

use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\FeatureFlags\FeatureFlags;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')->disabled()->dehydrated(false)->visibleOn('edit'),
                TextInput::make('status')
                    ->formatStateUsing(fn ($state) => ProjectStatus::tryFrom((string) $state)?->getLabel())
                    ->disabled()->dehydrated(false)->visibleOn('edit')
                    ->helperText('Status changes through the action buttons above, never by editing this field.'),
                Select::make('organization_id')
                    ->label('Organization')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabledOn('edit'),
                TextInput::make('title')->required()->maxLength(255),
                Textarea::make('summary')->rows(3)->columnSpanFull(),
                Textarea::make('scope_summary')->label('Scope')->rows(5)->columnSpanFull(),
                Select::make('project_type_id')->label('Type of work')->relationship('projectType', 'name')->searchable()->preload(),
                Select::make('skills')->relationship('skills', 'name')->multiple()->searchable()->preload(),
                TextInput::make('contract_value')->numeric()->minValue(0)->prefix('INR'),
                TextInput::make('currency')->default('INR')->disabled()->dehydrated(false)->helperText('Projects are INR only in v1.'),
                DatePicker::make('start_date'),
                DatePicker::make('due_date')->afterOrEqual('start_date'),
                Select::make('delivery_manager_id')
                    ->label('Delivery manager')
                    ->options(fn (): array => User::query()->where('role', UserRole::Admin->value)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Toggle::make('client_can_view_shortlist')
                    ->label('Client can view shortlist')
                    ->default(fn (): bool => app(FeatureFlags::class)->enabled(FeatureFlag::ClientCanViewShortlist))
                    ->helperText('Shows shortlisted applicants to the client by first name, headline and skills, once the SOW is accepted. New projects start from the client_can_view_shortlist feature flag.'),
                Toggle::make('hide_team')->label('Hide the team from the client')->helperText('Clients see first name, role and headline of each contributor once the SOW is accepted. Switch on to hide them for this project.'),
                TextInput::make('zoho_invoice_ref')->label('Zoho invoice reference')->maxLength(255),
            ]);
    }
}
