<?php

// Shared by the privacy tests. Not a test file itself: the tests load it with require_once, so each one also runs alone.

use App\Enums\AgreementType;
use App\Enums\AssignmentStatus;
use App\Enums\OnboardingState;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReviewStatus;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\EmailLog;
use App\Models\Message;
use App\Models\NotificationPreference;
use App\Models\Payout;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\Review;
use App\Models\Skill;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A contributor with a little of everything, and planted secrets in every field that only staff may see. Returns the person and
 * the other people whose data must never appear in the copy.
 *
 * @return array{person: User, other: User, project: Project, seat: Assignment}
 */
function richContributor(string $tag = ''): array
{
    $person = contributorIn(OnboardingState::Active, [
        'headline' => 'Market researcher', 'bio' => 'Ten years in research.', 'phone' => '9800000001', 'city' => 'Pune', 'experience_years' => 7,
    ], ['name' => "Asha Rao{$tag}", 'email' => "asha{$tag}@example.com"]);
    $person->skills()->attach(Skill::factory()->create(['name' => "Market Research{$tag}"])->id, ['level' => 'advanced', 'years' => 7]);

    $other = contributorIn(OnboardingState::Active, [], ['name' => "Other Person{$tag}", 'email' => "other{$tag}@example.com"]);
    $project = Project::factory()->status(ProjectStatus::Active)->create(['code' => 'GRB-2026-0'.random_int(100, 999), 'title' => "Pricing study{$tag}"]);
    $seat = Assignment::factory()->status(AssignmentStatus::Completed)->create([
        'project_id' => $project->id, 'user_id' => $person->id, 'role_title' => 'Analyst', 'agreed_amount' => 80000, 'removed_reason' => 'SECRET REMOVAL REASON',
    ]);
    Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $other->id, 'agreed_amount' => 98765]);

    Application::factory()->create([
        'user_id' => $person->id, 'motivation' => 'I can do this well.', 'relevant_experience' => 'Five studies.', 'admin_note' => 'SECRET ADMIN NOTE', 'expected_amount' => 5000,
    ]);

    Payout::factory()->status(PayoutStatus::Paid)->create(['assignment_id' => $seat->id, 'amount' => 40000, 'tds_amount' => 4000, 'notes' => 'SECRET PAYOUT NOTE']);
    Payout::factory()->status(PayoutStatus::Planned)->create(['assignment_id' => $seat->id, 'amount' => 12345, 'notes' => 'SECRET PLANNED PAYOUT']);

    $thread = Thread::factory()->forProject($project)->create(['subject' => 'Pricing study thread']);
    Message::factory()->create(['thread_id' => $thread->id, 'user_id' => $person->id, 'body' => 'My own message.']);
    Message::factory()->create(['thread_id' => $thread->id, 'user_id' => $other->id, 'body' => 'SOMEONE ELSES MESSAGE']);
    Message::factory()->internal()->create(['thread_id' => $thread->id, 'user_id' => $person->id, 'body' => 'SECRET INTERNAL MESSAGE']);

    ProofOfWork::factory()->create(['user_id' => $person->id, 'project_id' => $project->id, 'title' => 'Pricing proof', 'contribution_text' => 'I led the survey.']);

    Review::factory()->published()->create(['project_id' => $project->id, 'reviewee_id' => $person->id, 'comment' => 'Great work.']);
    Review::factory()->internal()->create(['project_id' => $project->id, 'reviewee_id' => $person->id, 'comment' => 'SECRET INTERNAL REVIEW']);
    Review::factory()->create(['project_id' => $project->id, 'reviewee_id' => $person->id, 'comment' => 'STILL IN MODERATION', 'status' => ReviewStatus::PendingModeration]);

    AgreementAcceptance::query()->create([
        'template_id' => AgreementTemplate::factory()->create(['type' => AgreementType::MemberAgreement, 'title' => 'Member Agreement', 'version' => $tag === '' ? 3 : random_int(100, 9999), 'is_active' => true])->id,
        'user_id' => $person->id, 'accepted_at' => now()->subDays(5), 'ip' => '203.0.113.9', 'user_agent' => 'TestBrowser/1.0',
    ]);
    NotificationPreference::query()->create(['user_id' => $person->id, 'category' => 'task', 'database' => true, 'mail' => false]);
    EmailLog::query()->create(['user_id' => $person->id, 'template' => 'App\\Notifications\\TaskAssigned', 'to' => $person->email, 'status' => 'sent', 'sent_at' => now()]);
    DB::table('notifications')->insert([
        'id' => (string) str()->uuid(), 'type' => 'x', 'notifiable_type' => $person->getMorphClass(), 'notifiable_id' => $person->id,
        'data' => json_encode(['title' => 'You have a task', 'body' => 'Draft the scan', 'url' => '/x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $person->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'remember_token' => 'REMEMBER-TOKEN'])->save();

    return compact('person', 'other', 'project', 'seat');
}
