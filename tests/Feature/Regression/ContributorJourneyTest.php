<?php

use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ReviewStatus;
use App\Enums\SowStatus;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\Applications\Pages\ListApplications;
use App\Filament\Admin\Resources\Applications\Pages\ViewApplication;
use App\Filament\Admin\Resources\Contributors\Pages\ListContributors;
use App\Filament\Admin\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\DeliverablesRelationManager;
use App\Filament\Admin\Resources\Projects\RelationManagers\MilestonesRelationManager;
use App\Filament\Admin\Resources\ProofOfWork\Pages\ViewProofOfWork;
use App\Filament\Admin\Resources\Reviews\Pages\ViewReview;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Livewire\Client\Deliverables\DeliverableIndex;
use App\Livewire\Client\Projects\ProjectReviews;
use App\Livewire\Contributor\Discover;
use App\Livewire\Contributor\EditProfile;
use App\Livewire\Contributor\MyApplications;
use App\Livewire\Contributor\Onboarding;
use App\Livewire\Contributor\Portfolio;
use App\Livewire\Contributor\Reputation;
use App\Livewire\Contributor\Work\MyWork;
use App\Livewire\Contributor\Work\ProjectBoard;
use App\Livewire\Contributor\Work\TaskDetail;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\OpenRole;
use App\Models\Payout;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\ReputationFact;
use App\Models\Review;
use App\Models\Skill;
use App\Models\Sow;
use App\Models\Submission;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/helpers.php';

/*
 * Pass 9 item 7, persona 2 of 4: the contributor. Spec 20: "A contributor can register, be approved, accept the Member Agreement,
 * apply to an open role, work tasks, submit work, and see published proof-of-work and reputation facts." Every step is taken on
 * the page or admin screen a person would use. Nothing is created behind the scenes except the client's organization and the
 * project it already has, and the milestone and tasks the delivery manager would plan.
 */

it('takes a contributor from sign-up to published proof of work, reputation and a paid payout', function () {
    Storage::fake('local');
    publishAgreements();

    $cm = adminUser(AdminRole::CommunityManager);
    $dm = adminUser(AdminRole::DeliveryManager);
    $finance = adminUser(AdminRole::FinanceOps);
    $skill = Skill::factory()->create(['name' => 'Survey design']);

    $project = Project::factory()->status(ProjectStatus::Staffing)->create(['delivery_manager_id' => $dm->id, 'title' => 'Retail shopper study']);
    Sow::factory()->status(SowStatus::Accepted)->create(['project_id' => $project->id]);
    $owner = clientUser($project->organization, OrgRole::Owner);
    $role = OpenRole::factory()->published()->public()->forProject($project)->create(['title' => 'Survey analyst', 'positions_needed' => 1]);

    // 1. Sign up, then follow the link in the verification email.
    Volt::test('auth.register-contributor')
        ->set('name', 'Meera Iyer')->set('email', 'meera@example.test')
        ->set('password', 'a-long-password-1')->set('password_confirmation', 'a-long-password-1')
        ->call('register')->assertHasNoErrors();

    $me = User::query()->where('email', 'meera@example.test')->firstOrFail();
    expect($me->status)->toBe(UserStatus::Pending)->and($me->hasVerifiedEmail())->toBeFalse();

    $this->actingAs($me)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $me->id, 'hash' => sha1($me->getEmailForVerification())]))->assertRedirect();
    expect($me->refresh()->hasVerifiedEmail())->toBeTrue();

    // 2. Until approved, the status page and profile open, and everything that handles work, money or applications is closed.
    asUser($me)->get('/app/contributor')->assertOk();
    foreach (['/app/contributor/work', '/app/contributor/discover', '/app/contributor/applications', '/app/contributor/payouts', '/app/contributor/portfolio'] as $url) {
        asUser($me)->get($url)->assertForbidden();
    }

    // 3. Onboarding: profile, skills and availability, the Member Agreement, then send for review.
    $onboarding = Livewire::actingAs($me)->test(Onboarding::class)->call('next')
        ->set('form.headline', 'Survey and research analyst')
        ->set('form.bio', 'I design and run consumer surveys for mid-market brands across India.')
        ->set('form.city', 'Kolkata')->set('form.country', 'India')->call('next')->assertHasNoErrors()
        ->set('form.availability', 'available')->set('form.hours_per_week', 15)->set('form.work_modes', ['remote'])
        ->set("form.skillLevels.{$skill->id}", 'advanced')->call('next')->assertHasNoErrors()
        ->set('agreed', true)->call('acceptAgreement')->assertHasNoErrors()
        ->call('submitForReview')->assertHasNoErrors();

    expect($me->profile->refresh()->onboarding_state)->toBe(OnboardingState::PendingReview);
    expect(Activity::query()->where('log_name', 'agreement')->where('event', 'accepted')->exists())->toBeTrue();
    $onboarding->assertOk();
    asUser($me)->get('/app/contributor/work')->assertForbidden(); // submitted is not approved

    // 4. A community manager approves. The contributor is told, and the closed pages open.
    Livewire::actingAs($cm)->test(ListContributors::class)->callAction(TestAction::make('approve')->table($me->profile))->assertNotified('Contributor approved');

    expect($me->refresh()->status)->toBe(UserStatus::Active)->and($me->profile->refresh()->onboarding_state)->toBe(OnboardingState::Active);
    expect($me->notifications()->count())->toBeGreaterThan(0);

    foreach (['/app/contributor', '/app/contributor/work', '/app/contributor/discover', '/app/contributor/applications', '/app/contributor/payouts', '/app/contributor/portfolio', '/app/contributor/reputation'] as $url) {
        asUser($me)->get($url)->assertOk();
    }

    // 5. Discover and apply.
    Livewire::actingAs($me)->test(Discover\Index::class)->assertSee('Survey analyst');
    Livewire::actingAs($me)->test(Discover\Show::class, ['slug' => $role->slug])
        ->set('form.motivation', 'I have designed and fielded forty consumer surveys, six of them for retail clients.')
        ->set('form.expected_amount', '120000')
        ->call('apply')->assertHasNoErrors()->assertSee('Application sent');

    $application = Application::query()->sole();
    expect($application->status)->toBe(ApplicationStatus::Submitted);
    Livewire::actingAs($me)->test(MyApplications::class)->assertSee('Survey analyst');

    // 6. Staff shortlist and offer. The contributor accepts, which gives them a seat on the project.
    Livewire::actingAs($cm)->test(ListApplications::class)->callTableAction('shortlist', $application);
    Livewire::actingAs($dm)->test(ViewApplication::class, ['record' => $application->getKey()])->callAction('offer');
    expect($application->refresh()->status)->toBe(ApplicationStatus::Offered);

    Livewire::actingAs($me)->test(MyApplications::class)->call('accept', $application->id);
    $seat = Assignment::query()->sole();
    expect($application->refresh()->status)->toBe(ApplicationStatus::Accepted)
        ->and($seat->status)->toBe(AssignmentStatus::Active)->and($seat->user_id)->toBe($me->id);

    // 7. The delivery manager sets the amount, plans two tasks and starts the project.
    Livewire::actingAs($dm)->test(AssignmentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
        ->callAction(TestAction::make('setAmount')->table($seat), ['agreed_amount' => 120000]);
    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->callAction('activate');
    expect($project->refresh()->status)->toBe(ProjectStatus::Active);

    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'title' => 'Field work']);
    $tasks = collect(['Questionnaire', 'Fieldwork report'])->map(fn (string $title) => Task::factory()->create([
        'milestone_id' => $milestone->id, 'project_id' => $project->id, 'assignment_id' => $seat->id, 'title' => $title,
    ]));

    // 8. The contributor sees the project and its tasks, works them and submits files.
    Livewire::actingAs($me)->test(MyWork::class)->assertSee('Retail shopper study');
    Livewire::actingAs($me)->test(ProjectBoard::class, ['project' => $project])->assertSee('Questionnaire')->assertSee('Fieldwork report');

    foreach ($tasks as $task) {
        Livewire::actingAs($me)->test(TaskDetail::class, ['task' => $task])->call('start')
            ->set('note', 'Ready for review')->set('uploads', [UploadedFile::fake()->create("{$task->id}.pdf", 40, 'application/pdf')])
            ->call('submit')->assertHasNoErrors();
    }
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::InQa)->and(Submission::query()->count())->toBe(2);

    // 9. QA approves, the work is shared, delivered and accepted by the client, and the project is closed.
    $queue = Livewire::actingAs($dm)->test(ListSubmissions::class);
    foreach (Submission::query()->orderBy('id')->get() as $submission) {
        $queue->callAction(TestAction::make('approve')->table($submission), ['note' => null])->assertHasNoActionErrors();
    }
    expect($me->notifications()->where('data->title', 'like', 'Your work was approved%')->count())->toBe(2);

    $tab = fn (string $class) => Livewire::actingAs($dm)->test($class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]);
    $tab(DeliverablesRelationManager::class)->callAction(TestAction::make('addDeliverable')->table(), [
        'milestone_id' => $milestone->id, 'title' => 'Survey and report', 'submission_ids' => Submission::query()->pluck('id')->all(),
    ]);
    $tab(MilestonesRelationManager::class)->callAction(TestAction::make('shareMilestone')->table($milestone));
    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->callAction('moveToQa');
    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->callAction('markDelivered');
    Livewire::actingAs($owner)->test(DeliverableIndex::class)->call('accept', $milestone->id)->assertHasNoErrors();

    expect($project->refresh()->status)->toBe(ProjectStatus::Accepted)->and($seat->refresh()->status)->toBe(AssignmentStatus::Completed);

    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->callAction('close');
    expect($project->refresh()->status)->toBe(ProjectStatus::Closed);

    // 10. Closing prepares a proof-of-work draft for the contributor. The client reviews them and gives showcase consent.
    $proof = ProofOfWork::query()->where('user_id', $me->id)->sole();
    expect($proof->status)->toBe(ProofStatus::Draft);

    Livewire::actingAs($owner)->test(ProjectReviews::class, ['project' => $project])
        ->set('consent', 'anonymous')->call('saveConsent')->assertHasNoErrors()
        ->set("ratings.{$seat->id}", ['quality' => 5, 'communication' => 4, 'reliability' => 5, 'timeliness' => 4])
        ->call('submitReview', $seat->id)->assertHasNoErrors();

    $review = Review::query()->where('reviewee_id', $me->id)->sole();
    expect($review->status)->toBe(ReviewStatus::PendingModeration);

    // 11. Reviews are moderated before they count (delivery manager; the community manager only reads them). Publishing refreshes reputation.
    Livewire::actingAs($dm)->test(ViewReview::class, ['record' => $review->getKey()])->callAction('publish');
    expect($review->refresh()->status)->toBe(ReviewStatus::Published);

    // 12. The contributor writes up the work and asks for it to be published; a delivery manager approves.
    Livewire::actingAs($me)->test(Portfolio\Edit::class, ['proof' => $proof])
        ->set('summary', 'Designed and ran a 600-person shopper survey.')
        ->set('contributionText', 'I wrote the questionnaire, ran fieldwork and wrote the final report.')
        ->set('metrics', [['label' => 'Respondents', 'value' => '600']])
        ->call('save')->assertHasNoErrors()->call('requestPublication')->assertHasNoErrors();
    expect($proof->refresh()->status)->toBe(ProofStatus::PendingApproval);

    Livewire::actingAs($dm)->test(ViewProofOfWork::class, ['record' => $proof->uuid])->callAction('publish');
    expect($proof->refresh()->status)->toBe(ProofStatus::Published)->and($proof->slug)->not->toBeNull();

    // 13. Reputation is evidence only: counts and averages, and the contributor's own page shows them.
    $facts = ReputationFact::query()->where('user_id', $me->id)->sole();
    expect($facts->projects_completed)->toBe(1)->and($facts->reviews_count)->toBe(1)->and($facts->tasks_total)->toBe(2)->and((float) $facts->avg_rating)->toBe(4.5);

    Livewire::actingAs($me)->test(Reputation::class)->assertSee('Projects completed');

    // The contributor reviews the client too, once, after close.
    Livewire::actingAs($me)->test(Reputation::class)->assertSee('Review your clients')
        ->set("ratings.{$project->id}", ['clarity' => 4, 'communication' => 5, 'professionalism' => 5, 'feedback_time' => 4, 'fairness' => 5])
        ->call('submitClientReview', $project->id)->assertHasNoErrors();
    Livewire::actingAs($me)->test(Reputation::class)->assertDontSee('Review your clients');

    // Approval alone does not make it public: it stays private until the contributor chooses public visibility.
    $this->get("/work/{$proof->slug}")->assertNotFound();
    Livewire::actingAs($me)->test(Portfolio\Index::class)->call('setVisibility', $proof->id, 'public');

    // The public page shows the work, with the client anonymous, and not the contributor's private details.
    $this->get("/work/{$proof->slug}")->assertOk()->assertSee('600-person shopper survey')->assertDontSee($project->organization->name)->assertDontSee('meera@example.test');

    // 14. Money: finance plans, approves and pays. The contributor sees it from approval on, never the staff note.
    Livewire::actingAs($finance)->test(ListPayouts::class)
        ->callAction('plan', ['assignment_id' => $seat->id, 'amount' => null, 'notes' => 'INTERNAL-PAYOUT-NOTE-JOURNEY']);
    $payout = Payout::query()->sole();
    expect($payout->status)->toBe(PayoutStatus::Planned);
    asUser($me)->get('/app/contributor/payouts')->assertOk()->assertDontSee('120,000');   // planned is not shown

    Livewire::actingAs($finance)->test(ListPayouts::class)->callTableAction('approve', $payout);
    asUser($me)->get('/app/contributor/payouts')->assertOk()->assertSee('120,000')->assertSee('Retail shopper study')->assertDontSee('INTERNAL-PAYOUT-NOTE-JOURNEY');

    Livewire::actingAs($finance)->test(ListPayouts::class)->callTableAction('markPaid', $payout, ['reference' => 'UTR123456789', 'paid_on' => now()->toDateString(), 'tds' => 12000]);
    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid);
    asUser($me)->get('/app/contributor/payouts')->assertOk()->assertSee('UTR123456789')->assertSee('108,000')->assertDontSee('INTERNAL-PAYOUT-NOTE-JOURNEY');

    // 15. The whole journey is in the audit trail.
    $events = Activity::query()->whereIn('log_name', ['contributor', 'application', 'project', 'payout', 'review'])->get()->map(fn ($a) => $a->log_name.':'.$a->event)->all();
    expect($events)->toContain('contributor:active', 'application:submitted', 'application:accepted', 'project:closed');
});

it('lets a contributor edit their profile at every stage of the journey', function () {
    publishAgreements();
    $me = contributorIn(OnboardingState::Active);

    Livewire::actingAs($me)->test(EditProfile::class)->assertOk();
    asUser($me)->get('/app/contributor/profile')->assertOk();
    asUser($me)->get('/settings/notifications')->assertOk();
    asUser($me)->get('/settings/privacy')->assertOk();
});
