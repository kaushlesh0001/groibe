@php
    $message = match (true) {
        $suspended => 'Your account is suspended. Please contact Groibe if you think this is a mistake.',
        $state === \App\Enums\OnboardingState::Registered => 'Finish your profile and skills to get started.',
        $state === \App\Enums\OnboardingState::ProfileComplete => 'Your profile is complete. Accept the Member Agreement and submit it for review.',
        $state === \App\Enums\OnboardingState::PendingReview => 'Your profile is with our team for review. We will email you when there is news.',
        $state === \App\Enums\OnboardingState::Rejected => 'We were not able to approve your profile at this time.',
        $state === \App\Enums\OnboardingState::Active => 'You are an approved contributor.',
        default => 'Your status is being updated.',
    };
@endphp
<p>{{ $message }}</p>
