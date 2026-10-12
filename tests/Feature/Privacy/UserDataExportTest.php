<?php

use App\Enums\OrgRole;
use App\Models\Application;
use App\Models\Brief;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Services\Privacy\UserDataExporter;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/RichContributor.php';

describe('what a copy of a contributor\'s data holds', function () {
    it('holds everything the person gave or the app recorded about them', function () {
        ['person' => $person] = richContributor();

        $data = app(UserDataExporter::class)->build($person);

        expect($data['account'])->toMatchArray(['name' => 'Asha Rao', 'email' => 'asha@example.com', 'role' => 'contributor', 'two_factor_enabled' => true])
            ->and($data['profile'])->toMatchArray(['headline' => 'Market researcher', 'bio' => 'Ten years in research.', 'phone' => '9800000001', 'city' => 'Pune', 'experience_years' => 7])
            ->and($data['skills'])->toBe([['skill' => 'Market Research', 'level' => 'advanced', 'years' => 7, 'verified' => false]])
            ->and($data['applications'][0])->toMatchArray(['motivation' => 'I can do this well.', 'relevant_experience' => 'Five studies.'])
            ->and($data['project_seats'][0])->toMatchArray(['role' => 'Analyst', 'status' => 'completed'])
            ->and($data['project_seats'][0]['project'])->toContain('Pricing study')
            ->and($data['messages'])->toHaveCount(1)->and($data['messages'][0]['body'])->toBe('My own message.')
            ->and($data['proof_of_work'][0]['contribution'])->toBe('I led the survey.')
            ->and($data['reviews_received'])->toHaveCount(1)->and($data['reviews_received'][0]['comment'])->toBe('Great work.')
            ->and($data['agreements_accepted'][0])->toMatchArray(['agreement' => 'Member Agreement', 'version' => 3, 'ip' => '203.0.113.9', 'browser' => 'TestBrowser/1.0'])
            ->and($data['notification_preferences'])->toBe([['category' => 'task', 'in_app' => true, 'email' => false]])
            ->and($data['notifications'][0])->toMatchArray(['title' => 'You have a task', 'body' => 'Draft the scan'])
            ->and($data['emails_sent_to_you'][0])->toMatchArray(['kind' => 'TaskAssigned', 'status' => 'sent']);
    });

    it('shows the payouts the person can see on their own payouts page, and not the ones still only planned', function () {
        ['person' => $person] = richContributor();

        $payouts = app(UserDataExporter::class)->build($person)['payouts'];

        expect($payouts)->toHaveCount(1)
            ->and($payouts[0])->toMatchArray(['amount' => '40000.00', 'tds' => '4000.00', 'status' => 'paid'])
            ->and($payouts[0]['transaction_reference'])->toStartWith('UTR')
            ->and($payouts[0])->not->toHaveKey('notes')->not->toHaveKey('created_by');
    });

    it('leaves out everything that only staff may see', function () {
        ['person' => $person] = richContributor();

        $json = json_encode(app(UserDataExporter::class)->build($person));

        foreach (['SECRET REMOVAL REASON', 'SECRET ADMIN NOTE', 'SECRET PAYOUT NOTE', 'SECRET PLANNED PAYOUT', 'SECRET INTERNAL MESSAGE', 'SECRET INTERNAL REVIEW', 'STILL IN MODERATION'] as $secret) {
            expect($json)->not->toContain($secret);
        }
    });

    it('leaves out other people\'s data and the account\'s secrets', function () {
        ['person' => $person, 'other' => $other] = richContributor();

        $json = json_encode(app(UserDataExporter::class)->build($person));

        expect($json)->not->toContain('SOMEONE ELSES MESSAGE')->not->toContain($other->name)->not->toContain($other->email)->not->toContain('98765')
            ->and($json)->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('REMEMBER-TOKEN')->not->toContain($person->getRawOriginal('password'))
            ->and($json)->not->toContain('two_factor_secret')->not->toContain('recovery');
    });

    it('is valid JSON and starts with when and what it is', function () {
        ['person' => $person] = richContributor();

        $data = app(UserDataExporter::class)->build($person);
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        expect(json_decode($json, true))->toBe($data)->and($data['generated_at'])->toBeString()->and($data['about_this_file'])->toContain('staff');
    });

    it('holds only the person\'s own data even when someone else has far more', function () {
        ['person' => $person] = richContributor();
        richContributor('-2');
        richContributor('-3');

        $data = app(UserDataExporter::class)->build($person);

        expect($data['messages'])->toHaveCount(1)->and($data['applications'])->toHaveCount(1)->and($data['project_seats'])->toHaveCount(1)
            ->and($data['agreements_accepted'])->toHaveCount(1)->and($data['payouts'])->toHaveCount(1);
    });

    it('makes the same number of queries whatever the size of the history', function () {
        ['person' => $person] = richContributor();
        $count = function () use ($person): int {
            $fresh = User::query()->findOrFail($person->id);
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(UserDataExporter::class)->build($fresh);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $before = $count();

        for ($i = 0; $i < 4; $i++) {
            $thread = Thread::factory()->forProject()->create();
            Message::factory()->create(['thread_id' => $thread->id, 'user_id' => $person->id]);
            Application::factory()->create(['user_id' => $person->id]);
        }

        expect($count())->toBe($before);
    });
});

describe('what a copy of a client\'s data holds', function () {
    it('holds the account, the organization role and the briefs they submitted, but not internal notes', function () {
        $organization = Organization::factory()->verified()->create(['name' => 'Acme Foods']);
        $client = clientUser($organization, OrgRole::Owner);
        $client->organizations()->updateExistingPivot($organization->id, ['designation' => 'Founder']);
        Brief::factory()->create([
            'organization_id' => $organization->id, 'submitted_by' => $client->id, 'title' => 'Market scan', 'problem' => 'We need a scan.',
            'internal_notes' => 'SECRET INTERNAL BRIEF NOTE', 'needs_info_note' => 'SECRET NEEDS INFO',
        ]);

        $data = app(UserDataExporter::class)->build($client);
        $json = json_encode($data);

        expect($data['account']['role'])->toBe('client')
            ->and($data['organizations'])->toBe([['organization' => 'Acme Foods', 'role' => 'owner', 'designation' => 'Founder']])
            ->and($data['briefs_submitted'][0])->toMatchArray(['title' => 'Market scan', 'problem' => 'We need a scan.', 'organization' => 'Acme Foods'])
            ->and($json)->not->toContain('SECRET INTERNAL BRIEF NOTE')->not->toContain('SECRET NEEDS INFO');
    });

    it('does not hold other members of the same organization', function () {
        $organization = Organization::factory()->verified()->create();
        $client = clientUser($organization, OrgRole::Owner);
        $colleague = clientUser($organization, OrgRole::Manager);

        $json = json_encode(app(UserDataExporter::class)->build($client));

        expect($json)->not->toContain($colleague->email)->not->toContain($colleague->name);
    });
});
