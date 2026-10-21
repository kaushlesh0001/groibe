{{-- The GROIBE wordmark (spec 10.3). Files are used as supplied: never retyped, recolored or stretched, so only the height is set. Variants: clean (light surfaces), dark (navy surfaces), white (only where a single-colour logo is needed). --}}
@props(['variant' => 'clean'])

<img
    src="{{ asset('brand/'.match ($variant) { 'dark' => 'groibe-logo-dark.svg', 'white' => 'groibe-logo-white.svg', default => 'groibe-logo-clean.svg' }) }}"
    alt="Groibe"
    width="127"
    height="32"
    {{ $attributes->merge(['class' => 'h-8 w-auto']) }}
>
