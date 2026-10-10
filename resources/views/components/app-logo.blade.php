{{-- The GROIBE wordmark (spec 10.3). Files are used as supplied: never retyped, recolored or stretched, so only the height is set. --}}
@props(['variant' => 'dark'])

<img
    src="{{ asset('brand/'.($variant === 'white' ? 'groibe-logo-white.svg' : 'groibe-logo.svg')) }}"
    alt="Groibe"
    width="127"
    height="32"
    {{ $attributes->merge(['class' => 'h-8 w-auto']) }}
>
