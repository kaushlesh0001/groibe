<?php

use App\Actions\Files\AttachPrivateFile;
use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\Deliverable;
use App\Models\InvoiceRef;
use App\Models\Message;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Payout;
use App\Models\Project;
use App\Models\Review;
use App\Models\Sow;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\AssertionFailedError;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One fully populated client organization for the regression suite (Pass 9 item 7): people at every org role, a brief with a
 * file, an active project with an accepted SOW, a shared milestone with a deliverable and a file, a project conversation with an
 * internal note, an internal task comment, a payout, an invoice reference and a published review.
 *
 * Every value that must stay private carries a marker made from the tag, so a leak anywhere is found by searching the page.
 * `tenant` markers belong to this organization and must never reach another one. `internal` markers belong to Groibe's own
 * staff and must never reach any client or contributor, this organization's included.
 *
 * @return array<string, mixed>
 */
function leakWorld(string $tag, ?User $dm = null, int $n = 1): array
{
    $dm ??= adminUser(AdminRole::DeliveryManager);
    // Different money in every world, so one world's own figures can never be mistaken for another's.
    $agreed = 6000000 + $n * 123400;
    $paid = 4000000 + $n * 412300;

    $org = Organization::factory()->verified()->create(['name' => "Org {$tag} Ltd"]);
    $people = [
        'owner' => clientUser($org, OrgRole::Owner),
        'manager' => clientUser($org, OrgRole::Manager),
        'viewer' => clientUser($org, OrgRole::Viewer),
    ];

    $contributor = contributorIn(OnboardingState::Active, ['headline' => "Headline {$tag}"], ['name' => "Zoya Surname{$tag}", 'email' => "zoya.{$tag}@contributors.example"]);

    $project = Project::factory()->status(ProjectStatus::Active)->create([
        'organization_id' => $org->id,
        'delivery_manager_id' => $dm->id,
        'title' => "Project {$tag}",
        'contract_value' => 880000,
    ]);
    $sow = Sow::factory()->status(SowStatus::Accepted)->create(['project_id' => $project->id, 'body_html' => "<p>Scope text {$tag}</p>"]);
    $seat = Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $contributor->id, 'agreed_amount' => $agreed]);

    $brief = Brief::factory()->create(['organization_id' => $org->id, 'title' => "Brief {$tag}", 'internal_notes' => "INTERNAL-NOTE-{$tag}"]);
    $briefFile = app(AttachPrivateFile::class)($brief, UploadedFile::fake()->create("brief-file-{$tag}.pdf", 20, 'application/pdf'), $people['owner']);

    $milestone = Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $project->id, 'title' => "Milestone {$tag}"]);
    $task = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $milestone->id, 'project_id' => $project->id, 'assignment_id' => $seat->id, 'title' => "Task {$tag}"]);
    $submission = Submission::factory()->status(SubmissionStatus::QaApproved)->create([
        'task_id' => $task->id,
        'assignment_id' => $seat->id,
        'user_id' => $contributor->id,
        'note' => "INTERNAL-SUBMISSION-NOTE-{$tag}",
        'qa_note' => "INTERNAL-QA-NOTE-{$tag}",
    ]);
    $submission->addMedia(UploadedFile::fake()->create("deliverable-{$tag}.pdf", 20, 'application/pdf'))->toMediaCollection(Submission::FILES);
    $deliverable = Deliverable::factory()->shared()->create(['milestone_id' => $milestone->id, 'title' => "Deliverable {$tag}", 'summary' => "Summary {$tag}"]);
    $deliverable->submissions()->attach($submission);

    TaskComment::factory()->internal()->create(['task_id' => $task->id, 'user_id' => $dm->id, 'body' => "INTERNAL-COMMENT-{$tag}"]);

    $thread = Thread::factory()->forProject($project)->create(['subject' => "Conversation {$tag}"]);
    Message::factory()->create(['thread_id' => $thread->id, 'user_id' => $people['owner']->id, 'body' => "MESSAGE-{$tag}"]);
    Message::factory()->internal()->create(['thread_id' => $thread->id, 'user_id' => $dm->id, 'body' => "INTERNAL-MESSAGE-{$tag}"]);

    $payout = Payout::factory()->status(PayoutStatus::Paid)->create([
        'assignment_id' => $seat->id, 'amount' => $paid, 'notes' => "INTERNAL-PAYOUT-NOTE-{$tag}", 'reference' => 'UTR'.strtoupper(substr(md5($tag), 0, 9)),
    ]);
    $invoice = InvoiceRef::factory()->status(InvoiceStatus::Sent)->create(['project_id' => $project->id, 'zoho_invoice_ref' => "INV-{$tag}"]);
    $review = Review::factory()->published()->create(['project_id' => $project->id, 'reviewee_id' => $contributor->id, 'reviewer_id' => $people['owner']->id, 'comment' => "REVIEW-{$tag}"]);

    return [
        'tag' => $tag,
        'dm' => $dm,
        'org' => $org,
        'people' => $people,
        'contributor' => $contributor,
        'project' => $project,
        'sow' => $sow,
        'seat' => $seat,
        'brief' => $brief,
        'briefFile' => $briefFile,
        'milestone' => $milestone,
        'task' => $task,
        'submission' => $submission,
        'deliverable' => $deliverable,
        'thread' => $thread,
        'payout' => $payout,
        'invoice' => $invoice,
        'review' => $review,
        // What only this organization may see.
        'tenant' => ["Org {$tag} Ltd", "Project {$tag}", "Brief {$tag}", "Milestone {$tag}", "Deliverable {$tag}", "Summary {$tag}", "MESSAGE-{$tag}",
            "Conversation {$tag}", "INV-{$tag}", "brief-file-{$tag}.pdf", "deliverable-{$tag}.pdf", "Scope text {$tag}", "REVIEW-{$tag}", "Task {$tag}"],
        // What only Groibe staff may see, and what a client must never learn about a contributor.
        'internal' => ["INTERNAL-NOTE-{$tag}", "INTERNAL-SUBMISSION-NOTE-{$tag}", "INTERNAL-QA-NOTE-{$tag}", "INTERNAL-COMMENT-{$tag}",
            "INTERNAL-MESSAGE-{$tag}", "INTERNAL-PAYOUT-NOTE-{$tag}", "Surname{$tag}", "zoya.{$tag}@contributors.example", (string) $agreed, number_format($agreed), (string) $paid, number_format($paid)],
    ];
}

/** A short-lived signed link to a private file, as the app hands one out. */
function signedFileLink(Media $media): string
{
    return URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['media' => $media->uuid]);
}

/** The first marker found in a page, or null. @param list<string> $markers */
function firstMarkerIn(string $html, array $markers): ?string
{
    $text = html_entity_decode($html);

    foreach ($markers as $marker) {
        if (str_contains($text, $marker) || str_contains($html, $marker)) {
            return $marker;
        }
    }

    return null;
}

/** A Livewire component that refused: forbidden or not found, both of which keep a record's existence private. */
function expectRefused(Testable $component): void
{
    try {
        $component->assertForbidden();
    } catch (AssertionFailedError) {
        $component->assertNotFound();
    }
}
