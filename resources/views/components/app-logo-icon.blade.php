{{-- The ring "O" of the GROIBE logo, for small spaces (spec 10.3). Square: set one size and let the other follow. --}}
@props(['variant' => 'dark'])

<img
    src="{{ asset('brand/'.($variant === 'white' ? 'groibe-ring-white.svg' : 'groibe-ring.svg')) }}"
    alt="Groibe"
    width="36"
    height="36"
    {{ $attributes->merge(['class' => 'size-9']) }}
>
