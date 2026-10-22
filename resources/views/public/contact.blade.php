<x-layouts.public title="Contact">
    @push('meta')
        <meta name="description" content="Send a message to the Groibe team.">
        <link rel="canonical" href="{{ route('contact') }}">
        <meta property="og:title" content="Contact | Groibe">
        <meta property="og:description" content="Send a message to the Groibe team.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('contact') }}">
    @endpush

    <div class="mx-auto flex max-w-2xl flex-col gap-6">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">Contact</h1>
            <p class="text-zinc-700">Send a message to the Groibe team. We use your details only to reply to you.</p>
            @if (filled(config('groibe.contact_email')))
                <p class="text-zinc-700">You can also write to <a class="text-brand-blue underline" href="mailto:{{ config('groibe.contact_email') }}">{{ config('groibe.contact_email') }}</a>.</p>
            @endif
        </div>

        @if (session('status'))
            <p role="status" class="rounded-lg border border-zinc-300 bg-zinc-50 p-4">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('contact.store') }}" class="flex flex-col gap-5" novalidate>
            @csrf

            {{-- Honeypot: hidden from people and from screen readers. Only a bot fills it in. --}}
            <div class="hidden" aria-hidden="true">
                <label for="company_website">Leave this field empty</label>
                <input id="company_website" name="company_website" type="text" tabindex="-1" autocomplete="off">
            </div>

            <div class="flex flex-col gap-1">
                <label for="name" class="font-medium">Your name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="120" autocomplete="name" aria-describedby="@error('name')name-error @enderror" @error('name') aria-invalid="true" @enderror class="rounded-lg border border-zinc-300 px-3 py-2">
                @error('name') <p id="name-error" class="text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="email" class="font-medium">Your email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email" aria-describedby="@error('email')email-error @enderror" @error('email') aria-invalid="true" @enderror class="rounded-lg border border-zinc-300 px-3 py-2">
                @error('email') <p id="email-error" class="text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="subject" class="font-medium">Subject <span class="font-normal text-zinc-600">(optional)</span></label>
                <input id="subject" name="subject" type="text" value="{{ old('subject') }}" maxlength="160" aria-describedby="@error('subject')subject-error @enderror" @error('subject') aria-invalid="true" @enderror class="rounded-lg border border-zinc-300 px-3 py-2">
                @error('subject') <p id="subject-error" class="text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="message" class="font-medium">Message</label>
                <textarea id="message" name="message" rows="7" required minlength="10" maxlength="4000" aria-describedby="@error('message')message-error @enderror" @error('message') aria-invalid="true" @enderror class="rounded-lg border border-zinc-300 px-3 py-2">{{ old('message') }}</textarea>
                @error('message') <p id="message-error" class="text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="w-fit rounded-lg bg-brand-green px-6 py-3 font-semibold text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-navy">Send message</button>
        </form>
    </div>
</x-layouts.public>
