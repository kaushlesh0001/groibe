<?php

namespace App\Filament\Admin\Resources\ProofOfWork\Schemas;

use App\Enums\ProofStatus;
use App\Models\ProofOfWork;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProofOfWorkInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Proof of work')
                ->columns(2)
                ->schema([
                    TextEntry::make('title'),
                    TextEntry::make('status')->badge()->color(fn (ProofStatus $state): string => $state->color()),
                    TextEntry::make('user.name')->label('Contributor'),
                    TextEntry::make('role_title')->label('Role')->placeholder('-'),
                    TextEntry::make('project.code')->label('Project')->placeholder('-'),
                    TextEntry::make('project.showcase_consent')->label('Client consent')->badge()->placeholder('-'),
                    TextEntry::make('visibility')->badge(),
                    TextEntry::make('client_display')->label('Client shown as')->badge(),
                    TextEntry::make('summary')->placeholder('Not written yet')->columnSpanFull(),
                    TextEntry::make('contribution_text')->label('Contribution')->placeholder('Not written yet')->columnSpanFull(),
                    TextEntry::make('metrics')
                        ->label('Outcome metrics')
                        ->state(fn (ProofOfWork $record): string => collect($record->outcome_metrics ?? [])
                            ->map(fn (array $row): string => $row['label'].': '.$row['value'])->implode('; ') ?: 'None')
                        ->columnSpanFull(),
                    TextEntry::make('skills')
                        ->state(fn (ProofOfWork $record): string => $record->skills->pluck('name')->implode(', ') ?: 'None')
                        ->columnSpanFull(),
                    TextEntry::make('published_at')->dateTime()->placeholder('Not published'),
                    TextEntry::make('approver.name')->label('Approved by')->placeholder('-'),
                ]),
        ]);
    }
}
