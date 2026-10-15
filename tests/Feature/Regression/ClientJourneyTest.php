<?php

use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\BriefStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\ReviewStatus;
use App\Enums\ShowcaseConsent;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Briefs\Pages\EditBrief;
use App\Filament\Admin\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\RelationManagers\SowsRelationManager;
use App\Livewire\Client\Agreements\AcceptMsa;
use App\Livewire\Client\Briefs\BriefForm;
use App\Livewire\Client\Dashboard;
use App\Livewire\Client\Deliverables\DeliverableIndex;
use App\Livewire\Client\Projects\ProjectReviews;
use App\Livewire\Client\Projects\ProjectShow;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
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
 * Pass 9 item 7, persona 3 of 4: the client. Spec 20: "A client can register, get verified, submit a brief, receive and accept a
 * SOW, see progress, accept deliverables, review contributors, and consent to showcase." The client's own steps run on their own
 * pages; Groibe's steps run on the admin screens. The organization is created at registration, pending, and an admin verifies it.
 */

it('takes a client from sign-up to an accepted deliverable, a review and showcase consent', function () {
    Storage::fake('local');
    publishAgreements();

    $dm = adminUser(AdminRole::DeliveryManager);
    $type = ProjectType::factory()->create();
    $skill = Skill::factory()->create();

    // 1. Sign up with the organization's details, then confirm the email address. The organization starts pending, with this person as owner.
    Volt::test('auth.register-client')
        ->set('name', 'Ravi Shah')->set('email', 'ravi@acme.test')
        ->set('password', 'a-long-password-1')->set('password_confirmation', 'a-long-password-1')
        ->set('designation', 'Founder')->set('organization_name', 'Acme Foods')->set('gstin', '27ABCDE1234F1Z5')
        ->set('industry', 'FMCG')->set('size_band', '51-200')->set('city', 'Mumbai')->set('state', 'Maharashtra')
        ->call('register')->assertHasNoErrors();

    $me = User::query()->where('email', 'ravi@acme.test')->firstOrFail();
    $org = Organization::query()->sole();
    expect($me->role)->toBe(UserRole::Client)
        ->and($org->status)->toBe(OrganizationStatus::Pending)->and($org->verified_at)->toBeNull()
        ->and($me->organizationRole($org))->toBe(OrgRole::Owner);

    $this->actingAs($me)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $me->id, 'hash' => sha1($me->getEmailForVerification())]))->assertRedirect();
    expect($me->refresh()->hasVerifiedEmail())->toBeTrue();
    asUser($me)->get('/app/client')->assertOk()->assertSee('Waiting for verification');

    // 2. Until Groibe verifies the organization, a draft can be prepared but not submitted.
    $admin = adminUser();

    // The form opens, but a pending organization cannot submit a brief.
    $fillBrief = fn () => Livewire::actingAs($me)->test(BriefForm::class)
        ->set('form.title', 'Shopper study')
        ->set('form.problem', 'We do not know why shoppers leave without buying in our regional stores.')
        ->set('form.desired_outcome', 'A clear ranked list of reasons, with evidence, within two months.')
        ->call('next')->set('form.project_type_id', $type->id)->set('form.skill_ids', [$skill->id])->call('next')
        ->set('form.budget_band', '3l_10l')->set('form.timeline_band', '1_3m')->call('next')
        ->set('form.confidentiality', 'standard')->call('next');
    try {
        $fillBrief()->call('submit');
    } catch (Throwable) {
        // A refusal may surface as an exception or as a message on the page; either way no brief may exist.
    }
    expect(Brief::query()->where('status', BriefStatus::Submitted->value)->count())->toBe(0);

    Livewire::actingAs($admin)->test(EditOrganization::class, ['record' => $org->getRouteKey()])
        ->fillForm(['status' => 'verified'])->call('save')->assertHasNoFormErrors();
    expect($org->refresh()->status)->toBe(OrganizationStatus::Verified)->and($org->verified_at)->not->toBeNull();
    asUser($me)->get('/app/client')->assertOk()->assertDontSee('Waiting for verification');

    // 3. The owner accepts the Client MSA, then submits a brief through the guided form.
    asUser($me)->get('/app/client')->assertOk()->assertSee('accept the Client MSA');
    Livewire::actingAs($me)->test(AcceptMsa::class)->set('confirmed', true)->call('accept')->assertHasNoErrors();

    $fillBrief()->call('submit')->assertHasNoErrors();

    // The refused attempt above left a saved draft (each step saves); the submitted one is the brief that counts.
    $brief = Brief::query()->where('status', BriefStatus::Submitted->value)->sole();
    expect($brief->status)->toBe(BriefStatus::Submitted);
    asUser($me)->get('/app/client/briefs')->assertOk()->assertSee('Shopper study');

    // 4. Groibe scopes it and turns it into a project. The client sees nothing of the project until the SOW is sent.
    $page = Livewire::actingAs($dm)->test(EditBrief::class, ['record' => $brief->getRouteKey()]);
    $page->callAction('markInReview')->callAction('markScoped');
    $page->callAction('convert', ['title' => 'Shopper study', 'delivery_manager_id' => $dm->id, 'contract_value' => 300000]);
    $project = Project::query()->sole();
    asUser($me)->get("/app/client/projects/{$project->uuid}")->assertForbidden();

    // 5. The SOW is sent; the client reads it, downloads it as a PDF, and accepts it with the checkbox and button.
    $sows = Livewire::actingAs($dm)->test(SowsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class]);
    $sows->callAction(TestAction::make('newSow')->table(), ['total_value' => 300000, 'payment_terms' => '50% on acceptance, 50% on delivery']);
    $sow = Sow::query()->sole();
    $sows->callAction(TestAction::make('sendSow')->table($sow));

    asUser($me)->get("/app/client/projects/{$project->uuid}")->assertOk()->assertSee('Accept Statement of Work');
    asUser($me)->get("/app/client/projects/{$project->uuid}/sows/1/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Livewire::actingAs($me)->test(ProjectShow::class, ['project' => $project->fresh()])->set('confirmed', true)->call('accept', 1)->assertHasNoErrors();
    expect($project->refresh()->status)->toBe(ProjectStatus::SowAccepted);

    // 6. Groibe staffs and runs the work (its own journey is tested elsewhere); the client sees the progress and the team by first name.
    $contributor = contributorIn(OnboardingState::Active, ['headline' => 'Survey analyst'], ['name' => 'Priya Sharma']);
    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->callAction('startStaffing');
    $seat = Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $contributor->id, 'role_title' => 'Survey analyst', 'agreed_amount' => 150000]);
    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->callAction('activate');

    $milestone = Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $project->id, 'title' => 'Survey and report']);
    $task = Task::factory()->status(TaskStatus::Approved)->create(['milestone_id' => $milestone->id, 'project_id' => $project->id, 'assignment_id' => $seat->id]);
    $submission = Submission::factory()->status(SubmissionStatus::QaApproved)->create(['task_id' => $task->id, 'assignment_id' => $seat->id, 'user_id' => $contributor->id, 'qa_note' => 'Internal QA remark']);
    $submission->addMedia(UploadedFile::fake()->create('shopper-report.pdf', 20, 'application/pdf'))->toMediaCollection(Submission::FILES);
    Deliverable::factory()->shared()->create(['milestone_id' => $milestone->id, 'title' => 'Shopper report'])->submissions()->attach($submission);

    Livewire::actingAs($me)->test(ProjectShow::class, ['project' => $project->fresh()])
        ->assertSee('Priya')->assertDontSee('Sharma')->assertSee('Survey analyst')->assertSee('0 of 1 milestone accepted')->assertDontSee('150000');
    Livewire::actingAs($me)->test(Dashboard::class)->assertSee('Shopper study');

    // 7. The work is delivered; the client opens the file and accepts the milestone, which accepts the project.
    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->callAction('moveToQa');
    Livewire::actingAs($dm)->test(EditProject::class, ['record' => $project->getRouteKey()])->callAction('markDelivered');
    expect($project->refresh()->status)->toBe(ProjectStatus::Delivered);

    $deliverables = Livewire::actingAs($me)->test(DeliverableIndex::class)->assertSee('Shopper report')->assertSee('shopper-report.pdf')->assertDontSee('Internal QA remark');
    preg_match('/href="([^"]*\/files\/[^"]*)"/', $deliverables->html(), $link);
    asUser($me)->get(html_entity_decode($link[1]))->assertOk();

    $deliverables->call('accept', $milestone->id)->assertHasNoErrors();
    expect($milestone->refresh()->status)->toBe(MilestoneStatus::ClientAccepted)
        ->and($project->refresh()->status)->toBe(ProjectStatus::Accepted)
        ->and($seat->refresh()->status)->toBe(AssignmentStatus::Completed);

    // 8. The client reviews the contributor (held for moderation) and gives showcase consent, which is recorded.
    Livewire::actingAs($me)->test(ProjectReviews::class, ['project' => $project])
        ->assertSee('Priya, Survey analyst')->assertDontSee('Sharma')
        ->set("ratings.{$seat->id}", ['quality' => 5, 'communication' => 5, 'reliability' => 4, 'timeliness' => 5])->call('submitReview', $seat->id)->assertHasNoErrors()
        ->set('consent', 'named')->call('saveConsent')->assertHasNoErrors();

    expect(Review::query()->where('reviewee_id', $contributor->id)->sole()->status)->toBe(ReviewStatus::PendingModeration)
        ->and($project->refresh()->showcase_consent)->toBe(ShowcaseConsent::Named);
    expect(Activity::query()->where('event', 'showcase_consent')->count())->toBe(1);

    // The review is not public until moderated: no one else can read it.
    asUser($contributor)->get('/app/contributor/reputation')->assertOk()->assertDontSee('Survey and report');
});

it('lets only the owner and managers act, and keeps a viewer read-only', function () {
    publishAgreements();
    $seat = projectWithCompletedSeat(ProjectStatus::Delivered);
    $viewer = clientUser($seat['organization'], OrgRole::Viewer);
    $milestone = Milestone::factory()->status(MilestoneStatus::Shared)->create(['project_id' => $seat['project']->id]);
    Deliverable::factory()->shared()->create(['milestone_id' => $milestone->id]);

    asUser($viewer)->get('/app/client/deliverables')->assertOk();
    Livewire::actingAs($viewer)->test(DeliverableIndex::class)->call('accept', $milestone->id);

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Shared);
});
