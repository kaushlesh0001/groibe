<?php

use App\Actions\Projects\CloseProject;
use App\Actions\Reputation\CreateProofOfWorkDrafts;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\ClientDisplay;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Exceptions\IllegalTransition;
use App\Models\Assignment;
use App\Models\OrganizationStat;
use App\Models\ProofOfWork;
use App\Models\ReputationFact;
use App\Models\Skill;
use App\Notifications\ProjectClosed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
});

it('creates one private draft per completed assignment when the project closes', function () {
    $seat = projectWithCompletedSeat();
    $other = publicContributor('Ravi Iyer');
    Assignment::factory()->status(AssignmentStatus::Completed)->create(['project_id' => $seat['project']->id, 'user_id' => $other->id, 'role_title' => 'Designer']);
    $removed = publicContributor('Kiran Das');
    Assignment::factory()->status(AssignmentStatus::Removed)->create(['project_id' => $seat['project']->id, 'user_id' => $removed->id]);

    app(CloseProject::class)($seat['project'], $this->dm);

    expect($seat['project']->refresh()->status)->toBe(ProjectStatus::Closed)
        ->and(ProofOfWork::query()->count())->toBe(2)
        ->and(ProofOfWork::query()->where('user_id', $removed->id)->exists())->toBeFalse();

    $draft = ProofOfWork::query()->where('user_id', $seat['contributor']->id)->firstOrFail();

    expect($draft->status)->toBe(ProofStatus::Draft)
        ->and($draft->visibility)->toBe(ProofVisibility::Private)
        ->and($draft->client_display)->toBe(ClientDisplay::Anonymous)
        ->and($draft->role_title)->toBe('Research Analyst')
        ->and($draft->project_id)->toBe($seat['project']->id)
        ->and($draft->title)->not->toContain($seat['project']->title);
});

it('takes draft skills from the project skills the contributor holds, else all project skills', function () {
    $seat = projectWithCompletedSeat();
    $held = $seat['contributor']->skills()->first();
    $extra = Skill::factory()->create();
    $seat['project']->skills()->attach([$held->id, $extra->id]);

    app(CloseProject::class)($seat['project'], $this->dm);

    expect(ProofOfWork::query()->firstOrFail()->skills->pluck('id')->all())->toBe([$held->id]);

    // A contributor holding none of the project skills gets all of them.
    $second = projectWithCompletedSeat();
    $second['project']->skills()->attach($extra->id);
    $second['contributor']->skills()->detach();

    app(CloseProject::class)($second['project'], $this->dm);

    expect(ProofOfWork::query()->where('user_id', $second['contributor']->id)->firstOrFail()->skills->pluck('id')->all())->toBe([$extra->id]);
});

it('does not duplicate drafts if drafting runs twice', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);

    expect(app(CreateProofOfWorkDrafts::class)($seat['project'], $this->dm))->toHaveCount(1)
        ->and(app(CreateProofOfWorkDrafts::class)($seat['project'], $this->dm))->toHaveCount(0)
        ->and(ProofOfWork::query()->count())->toBe(1);
});

it('asks the client team and the contributors to act, and recomputes reputation', function () {
    Notification::fake();
    $seat = projectWithCompletedSeat();

    app(CloseProject::class)($seat['project'], $this->dm);

    Notification::assertSentTo($seat['owner'], ProjectClosed::class, fn (ProjectClosed $n) => $n->audience === 'client');
    Notification::assertSentTo($seat['contributor'], ProjectClosed::class, fn (ProjectClosed $n) => $n->audience === 'contributor');
    expect(ReputationFact::query()->where('user_id', $seat['contributor']->id)->exists())->toBeTrue()
        ->and(OrganizationStat::query()->where('organization_id', $seat['organization']->id)->exists())->toBeTrue();
});

it('writes the close to the activity log', function () {
    $seat = projectWithCompletedSeat();

    app(CloseProject::class)($seat['project'], $this->dm);

    expect(Activity::query()->where('log_name', 'proof_of_work')->where('event', 'drafted')->count())->toBe(1);
});

it('only closes an accepted project, and only for an admin who may transition it', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Delivered);

    expect(fn () => app(CloseProject::class)($seat['project'], $this->dm))->toThrow(IllegalTransition::class)
        ->and(ProofOfWork::query()->count())->toBe(0);

    $accepted = projectWithCompletedSeat();

    expect(fn () => app(CloseProject::class)($accepted['project'], $accepted['owner']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CloseProject::class)($accepted['project'], $accepted['contributor']))->toThrow(AuthorizationException::class)
        ->and(ProofOfWork::query()->count())->toBe(0);
});
