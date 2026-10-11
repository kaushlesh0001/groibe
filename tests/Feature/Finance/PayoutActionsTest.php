<?php

use App\Actions\Assignments\SetAssignmentAmount;
use App\Actions\Payouts\ApprovePayout;
use App\Actions\Payouts\CancelPayout;
use App\Actions\Payouts\CreatePayout;
use App\Actions\Payouts\DeletePayout;
use App\Actions\Payouts\MarkPayoutPaid;
use App\Actions\Payouts\UpdatePayout;
use App\Enums\AdminRole;
use App\Enums\AssignmentStatus;
use App\Enums\OnboardingState;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Enums\RateType;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Payout;
use App\Models\Project;
use App\Notifications\PayoutStatusChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();

    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->project = Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $this->dm->id]);
    $this->contributor = contributorIn(OnboardingState::Active);
    $this->seat = Assignment::factory()->active()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->contributor->id,
        'rate_type' => RateType::Fixed,
        'agreed_amount' => 40000,
    ]);
    $this->plan = fn (array $data = [], $seat = null) => app(CreatePayout::class)($seat ?? $this->seat, $this->finance, $data);
});

describe('planning a payout', function () {
    it('defaults to the agreed amount and starts planned, owned by the seat', function () {
        $payout = ($this->plan)();

        expect($payout->status)->toBe(PayoutStatus::Planned)
            ->and((string) $payout->amount)->toBe('40000.00')
            ->and($payout->user_id)->toBe($this->contributor->id)
            ->and($payout->project_id)->toBe($this->project->id)
            ->and($payout->currency)->toBe('INR')
            ->and($payout->created_by)->toBe($this->finance->id)
            ->and($payout->approved_at)->toBeNull();

        expect(Activity::query()->where('log_name', 'payout')->where('event', 'planned')->exists())->toBeTrue();
    });

    it('accepts a smaller amount, a milestone of the same project and notes', function () {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

        $payout = ($this->plan)(['amount' => '12500.50', 'milestone_id' => $milestone->id, 'notes' => 'First half']);

        expect((string) $payout->amount)->toBe('12500.50')->and($payout->milestone_id)->toBe($milestone->id)->and($payout->notes)->toBe('First half');
    });

    it('refuses a milestone from another project', function () {
        $other = Milestone::factory()->create();

        expect(fn () => ($this->plan)(['milestone_id' => $other->id]))->toThrow(ActionBlocked::class, 'does not belong');
    });

    it('needs an agreed amount, an active or completed seat, and a project past draft', function () {
        $noAmount = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'agreed_amount' => null]);
        $invited = Assignment::factory()->create(['project_id' => $this->project->id, 'agreed_amount' => 1000]);
        $removed = Assignment::factory()->status(AssignmentStatus::Removed)->create(['project_id' => $this->project->id, 'agreed_amount' => 1000]);
        $draftProject = Project::factory()->status(ProjectStatus::Draft)->create();
        $inDraft = Assignment::factory()->active()->create(['project_id' => $draftProject->id, 'agreed_amount' => 1000]);

        expect(fn () => ($this->plan)([], $noAmount))->toThrow(ActionBlocked::class, 'agreed amount')
            ->and(fn () => ($this->plan)([], $invited))->toThrow(ActionBlocked::class, 'active or completed')
            ->and(fn () => ($this->plan)([], $removed))->toThrow(ActionBlocked::class, 'active or completed')
            ->and(fn () => ($this->plan)([], $inDraft))->toThrow(ActionBlocked::class, 'draft');

        expect(($this->plan)([], Assignment::factory()->status(AssignmentStatus::Completed)->create(['project_id' => $this->project->id, 'agreed_amount' => 500]))->status)
            ->toBe(PayoutStatus::Planned);
    });

    it('rejects zero, negative, text and over-precise amounts', function (mixed $amount) {
        expect(fn () => ($this->plan)(['amount' => $amount]))->toThrow(ActionBlocked::class);
    })->with(['0', '-5', 'abc', '10.123', '1e5', '99999999999999.00']);

    it('caps a fixed fee seat at the agreed total, counting everything not cancelled', function () {
        ($this->plan)(['amount' => 30000]);

        expect(fn () => ($this->plan)(['amount' => 10000.01]))->toThrow(ActionBlocked::class, 'above the agreed amount');

        // Exactly the remainder fits, and a cancelled payout frees its share.
        $second = ($this->plan)(['amount' => 10000]);
        expect(fn () => ($this->plan)(['amount' => 1]))->toThrow(ActionBlocked::class);

        app(CancelPayout::class)($second, $this->finance, 'Wrong month');
        expect((string) ($this->plan)(['amount' => 10000])->amount)->toBe('10000.00');
    });

    it('does not cap an hourly seat', function () {
        $hourly = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'rate_type' => RateType::Hourly, 'agreed_amount' => 500]);

        ($this->plan)(['amount' => 500], $hourly);
        ($this->plan)(['amount' => 5000], $hourly);

        expect(Payout::query()->where('assignment_id', $hourly->id)->count())->toBe(2);
    });

    it('is for finance and super admin only', function (AdminRole $role, bool $allowed) {
        $actor = adminUser($role);
        $call = fn () => app(CreatePayout::class)($this->seat, $actor);

        $allowed ? expect($call()->status)->toBe(PayoutStatus::Planned) : expect($call)->toThrow(AuthorizationException::class);
    })->with([
        [AdminRole::FinanceOps, true],
        [AdminRole::SuperAdmin, true],
        [AdminRole::DeliveryManager, false],
        [AdminRole::CommunityManager, false],
    ]);

    it('refuses a contributor and a client', function () {
        expect(fn () => app(CreatePayout::class)($this->seat, $this->contributor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CreatePayout::class)($this->seat, clientUser()))->toThrow(AuthorizationException::class);
    });
});

describe('editing and deleting a planned payout', function () {
    it('edits amount, milestone and notes while planned, still under the cap', function () {
        $payout = ($this->plan)(['amount' => 10000]);
        $other = ($this->plan)(['amount' => 20000]);

        $payout = app(UpdatePayout::class)($payout, $this->finance, ['amount' => '15000', 'notes' => 'Revised']);

        expect((string) $payout->amount)->toBe('15000.00')->and($payout->notes)->toBe('Revised')
            ->and(fn () => app(UpdatePayout::class)($payout, $this->finance, ['amount' => '20001']))->toThrow(ActionBlocked::class, 'above the agreed amount')
            ->and($other->fresh()->status)->toBe(PayoutStatus::Planned);
    });

    it('locks the figures once approved', function () {
        $payout = app(ApprovePayout::class)(($this->plan)(), $this->finance);

        expect(fn () => app(UpdatePayout::class)($payout, $this->finance, ['amount' => '1']))->toThrow(ActionBlocked::class, 'Only a planned payout');
    });

    it('deletes a planned payout and nothing else', function () {
        $planned = ($this->plan)(['amount' => 1000]);
        app(DeletePayout::class)($planned, $this->finance);

        expect(Payout::query()->find($planned->id))->toBeNull();

        foreach ([PayoutStatus::Approved, PayoutStatus::Paid, PayoutStatus::Cancelled] as $status) {
            $kept = Payout::factory()->status($status)->create(['assignment_id' => $this->seat->id, 'amount' => 1]);

            expect(fn () => app(DeletePayout::class)($kept, $this->finance))->toThrow(ActionBlocked::class);
            expect(Payout::query()->find($kept->id))->not->toBeNull();
        }
    });

    it('refuses a delivery manager', function () {
        $payout = ($this->plan)();

        expect(fn () => app(UpdatePayout::class)($payout, $this->dm, ['amount' => '1']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeletePayout::class)($payout, $this->dm))->toThrow(AuthorizationException::class);
    });
});

describe('status transitions', function () {
    it('walks planned, approved, paid and records TDS, UTR and date', function () {
        $payout = ($this->plan)();
        $payout = app(ApprovePayout::class)($payout, $this->finance);

        expect($payout->status)->toBe(PayoutStatus::Approved)->and($payout->approved_at)->not->toBeNull();

        $payout = app(MarkPayoutPaid::class)($payout, $this->finance, ' UTR123456789 ', now()->subDay()->toDateString(), '4000');

        expect($payout->status)->toBe(PayoutStatus::Paid)
            ->and($payout->reference)->toBe('UTR123456789')
            ->and($payout->paid_at->toDateString())->toBe(now()->subDay()->toDateString())
            ->and((string) $payout->tds_amount)->toBe('4000.00')
            ->and($payout->net())->toBe('36000.00');
    });

    it('allows every legal move and refuses every other', function (PayoutStatus $from, PayoutStatus $to, bool $legal) {
        expect($from->canTransitionTo($to))->toBe($legal);
    })->with(function () {
        foreach (PayoutStatus::cases() as $from) {
            foreach (PayoutStatus::cases() as $to) {
                $legal = in_array([$from, $to], [
                    [PayoutStatus::Planned, PayoutStatus::Approved], [PayoutStatus::Planned, PayoutStatus::Cancelled],
                    [PayoutStatus::Approved, PayoutStatus::Paid], [PayoutStatus::Approved, PayoutStatus::Cancelled],
                ], true);

                yield "{$from->value} to {$to->value}" => [$from, $to, $legal];
            }
        }
    });

    it('refuses to pay a payout that is not approved, to approve twice, or to touch a paid one', function () {
        $planned = ($this->plan)(['amount' => 1000]);

        expect(fn () => app(MarkPayoutPaid::class)($planned, $this->finance, 'UTR123456', today()))->toThrow(IllegalTransition::class);

        $approved = app(ApprovePayout::class)($planned, $this->finance);
        expect(fn () => app(ApprovePayout::class)($approved, $this->finance))->toThrow(IllegalTransition::class);

        $paid = app(MarkPayoutPaid::class)($approved, $this->finance, 'UTR123456', today());
        expect(fn () => app(CancelPayout::class)($paid, $this->finance, 'Oops'))->toThrow(IllegalTransition::class)
            ->and(fn () => app(ApprovePayout::class)($paid, $this->finance))->toThrow(IllegalTransition::class);
    });

    it('validates the reference, the date and the TDS', function (string $reference, mixed $date, mixed $tds, string $message) {
        $approved = app(ApprovePayout::class)(($this->plan)(['amount' => 1000]), $this->finance);

        expect(fn () => app(MarkPayoutPaid::class)($approved, $this->finance, $reference, $date, $tds))->toThrow(ActionBlocked::class, $message);
        expect($approved->fresh()->status)->toBe(PayoutStatus::Approved);
    })->with([
        'too short' => ['UTR1', '2026-01-01', null, 'transaction reference'],
        'punctuation' => ['UTR-123456', '2026-01-01', null, 'transaction reference'],
        'spaces inside' => ['UTR 123456', '2026-01-01', null, 'transaction reference'],
        'too long' => [str_repeat('A', 31), '2026-01-01', null, 'transaction reference'],
        'no date' => ['UTR123456', 'not a date', null, 'date'],
        'future date' => ['UTR123456', '2099-01-01', null, 'future'],
        'tds above amount' => ['UTR123456', '2026-01-01', '1000.01', 'TDS cannot be more'],
        'negative tds' => ['UTR123456', '2026-01-01', '-1', 'TDS'],
    ]);

    it('cancels a planned or approved payout with a reason, and not without one', function () {
        $planned = ($this->plan)(['amount' => 1000]);

        expect(fn () => app(CancelPayout::class)($planned, $this->finance, '  '))->toThrow(ActionBlocked::class, 'reason');

        expect(app(CancelPayout::class)($planned, $this->finance, 'Duplicate')->status)->toBe(PayoutStatus::Cancelled);

        $approved = app(ApprovePayout::class)(($this->plan)(['amount' => 1000]), $this->finance);
        expect(app(CancelPayout::class)($approved, $this->finance, 'Scope cut')->status)->toBe(PayoutStatus::Cancelled);

        $log = Activity::query()->where('log_name', 'payout')->where('event', 'cancelled')->get();
        expect($log->pluck('properties.reason')->all())->toContain('Duplicate', 'Scope cut');
    });

    it('is finance only, and the delivery manager can do none of it', function () {
        $payout = ($this->plan)(['amount' => 1000]);

        foreach ([ApprovePayout::class => [], CancelPayout::class => ['Because']] as $class => $args) {
            expect(fn () => app($class)($payout, $this->dm, ...$args))->toThrow(AuthorizationException::class);
        }

        $approved = app(ApprovePayout::class)($payout, $this->finance);
        expect(fn () => app(MarkPayoutPaid::class)($approved, $this->dm, 'UTR123456', today()))->toThrow(AuthorizationException::class);
    });

    it('lets the same finance person plan and approve, and logs both', function () {
        $payout = app(ApprovePayout::class)(($this->plan)(), $this->finance);

        expect($payout->created_by)->toBe($this->finance->id)
            ->and(Activity::query()->where('log_name', 'payout')->where('causer_id', $this->finance->id)->whereIn('event', ['planned', 'approved'])->count())->toBe(2);
    });

    it('keeps the reference and notes out of the audit log values', function () {
        $payout = ($this->plan)(['notes' => 'Secret negotiation note']);
        $payout = app(ApprovePayout::class)($payout, $this->finance);
        app(MarkPayoutPaid::class)($payout, $this->finance, 'UTRSECRET99', today());

        expect(Activity::query()->where('log_name', 'payout')->orWhere('subject_type', Payout::class)->get()->toJson())
            ->not->toContain('UTRSECRET99')->not->toContain('Secret negotiation');
    });
});

describe('telling the contributor', function () {
    it('says nothing while planned, tells them on approve, paid and cancel-after-approve', function () {
        $payout = ($this->plan)();
        Notification::assertNothingSent();

        $payout = app(ApprovePayout::class)($payout, $this->finance);
        Notification::assertSentTo($this->contributor, PayoutStatusChanged::class, fn ($n) => $n->status === PayoutStatus::Approved);

        app(MarkPayoutPaid::class)($payout, $this->finance, 'UTR123456', today());
        Notification::assertSentTo($this->contributor, PayoutStatusChanged::class, fn ($n) => $n->status === PayoutStatus::Paid);

        $second = app(ApprovePayout::class)(Payout::factory()->create(['assignment_id' => $this->seat->id, 'amount' => 1]), $this->finance);
        app(CancelPayout::class)($second, $this->finance, 'Mistake');
        Notification::assertSentTo($this->contributor, PayoutStatusChanged::class, fn ($n) => $n->status === PayoutStatus::Cancelled);
    });

    it('stays silent when a plan the contributor never saw is cancelled', function () {
        app(CancelPayout::class)(($this->plan)(), $this->finance, 'Planned by mistake');

        Notification::assertNothingSent();
    });

    it('carries no amount or reference in the text, in the stored data or in the email', function () {
        $payout = app(ApprovePayout::class)(($this->plan)(['amount' => 12345]), $this->finance);
        app(MarkPayoutPaid::class)($payout, $this->finance, 'UTRSECRET99', today());

        Notification::assertSentTo($this->contributor, PayoutStatusChanged::class, function (PayoutStatusChanged $n) {
            $text = json_encode($n->toArray($this->contributor)).$n->toMail($this->contributor)->render();

            return ! str_contains($text, '12345') && ! str_contains($text, 'UTRSECRET99') && ! str_contains($text, '12,345');
        });
    });
});

describe('the agreed amount of a seat', function () {
    it('can be set by a delivery manager and super admin, and by nobody in finance', function () {
        $seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'agreed_amount' => null]);

        expect((string) app(SetAssignmentAmount::class)($seat, $this->dm, '25000')->agreed_amount)->toBe('25000.00')
            ->and((string) app(SetAssignmentAmount::class)($seat, adminUser(AdminRole::SuperAdmin), '26000')->agreed_amount)->toBe('26000.00')
            ->and(fn () => app(SetAssignmentAmount::class)($seat, $this->finance, '1'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetAssignmentAmount::class)($seat, adminUser(AdminRole::CommunityManager), '1'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetAssignmentAmount::class)($seat, $this->contributor, '1'))->toThrow(AuthorizationException::class);
    });

    it('cannot drop below what is already planned or paid, and cannot be set on a removed seat', function () {
        ($this->plan)(['amount' => 30000]);

        expect(fn () => app(SetAssignmentAmount::class)($this->seat, $this->dm, '29999.99'))->toThrow(ActionBlocked::class, 'already planned')
            ->and((string) app(SetAssignmentAmount::class)($this->seat, $this->dm, '30000')->agreed_amount)->toBe('30000.00');

        $removed = Assignment::factory()->status(AssignmentStatus::Removed)->create(['project_id' => $this->project->id]);
        expect(fn () => app(SetAssignmentAmount::class)($removed, $this->dm, '100'))->toThrow(ActionBlocked::class);
    });

    it('rejects a bad amount and logs the change', function () {
        expect(fn () => app(SetAssignmentAmount::class)($this->seat, $this->dm, '0'))->toThrow(ActionBlocked::class)
            ->and(fn () => app(SetAssignmentAmount::class)($this->seat, $this->dm, 'lots'))->toThrow(ActionBlocked::class);

        app(SetAssignmentAmount::class)($this->seat, $this->dm, '45000');

        expect(Activity::query()->where('event', 'amount_set')->first()->properties->toArray())->toMatchArray(['from' => '40000.00', 'to' => '45000.00']);
    });
});
