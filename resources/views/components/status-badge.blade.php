@props(['status'])

@php
    $color = match ($status->color()) {
        'info' => 'blue',
        'warning' => 'amber',
        'success' => 'green',
        'danger' => 'red',
        'primary' => 'sky',
        default => 'zinc',
    };
@endphp

<flux:badge :color="$color" size="sm">{{ $status->getLabel() }}</flux:badge>
