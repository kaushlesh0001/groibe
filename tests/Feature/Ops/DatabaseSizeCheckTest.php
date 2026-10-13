<?php

use App\Enums\AdminRole;
use App\Notifications\DatabaseSizeWarning;
use App\Services\Ops\DatabaseSize;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    config(['groibe.ops.db_size_limit_mb' => 1000, 'groibe.ops.db_size_warn_percent' => 80]);

    $this->super = adminUser();
    $this->manager = adminUser(AdminRole::DeliveryManager);
    $this->finance = adminUser(AdminRole::FinanceOps);
});

/** Replaces the measuring query with a fixed size. */
function databaseIs(float $megabytes): void
{
    app()->instance(DatabaseSize::class, new class($megabytes) extends DatabaseSize
    {
        public function __construct(private float $fixed) {}

        public function megabytes(): float
        {
            return $this->fixed;
        }
    });
}

it('warns super admins in the bell and by email at 80% of the limit, and nobody else', function () {
    databaseIs(800.0);

    $this->artisan('groibe:check-database-size')->assertSuccessful();

    Notification::assertSentTo($this->super, DatabaseSizeWarning::class, function (DatabaseSizeWarning $warning, array $channels): bool {
        return $channels === ['database', 'mail'] && $warning->percent === 80 && $warning->limitMb === 1000;
    });
    Notification::assertNotSentTo($this->manager, DatabaseSizeWarning::class);
    Notification::assertNotSentTo($this->finance, DatabaseSizeWarning::class);
});

it('stays quiet just under 80%', function () {
    databaseIs(799.0);

    $this->artisan('groibe:check-database-size')->assertSuccessful();

    Notification::assertNothingSent();
});

it('warns once a day while the database stays above, and again the next day', function () {
    databaseIs(950.0);

    $this->artisan('groibe:check-database-size')->assertSuccessful();
    $this->artisan('groibe:check-database-size')->assertSuccessful();

    Notification::assertSentToTimes($this->super, DatabaseSizeWarning::class, 1);

    $this->travel(1)->day();

    $this->artisan('groibe:check-database-size')->assertSuccessful();

    Notification::assertSentToTimes($this->super, DatabaseSizeWarning::class, 2);
});

it('uses the limit from the setting, so a smaller host plan warns sooner', function () {
    config(['groibe.ops.db_size_limit_mb' => 500]);
    databaseIs(420.0);

    $this->artisan('groibe:check-database-size')->assertSuccessful();

    Notification::assertSentTo($this->super, DatabaseSizeWarning::class, fn (DatabaseSizeWarning $warning): bool => $warning->percent === 84 && $warning->limitMb === 500);
});

it('defaults the limit to 1024 MB', function () {
    expect(file_get_contents(base_path('config/groibe.php')))->toContain("env('DB_SIZE_LIMIT_MB', 1024)")
        ->and(file_get_contents(base_path('.env.example')))->toContain('DB_SIZE_LIMIT_MB=1024');
});

it('writes the size to the log', function () {
    Log::spy();
    databaseIs(123.45);

    $this->artisan('groibe:check-database-size')->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Database size checked.'
        && $context['mb'] === 123.45 && $context['limit_mb'] === 1000 && $context['percent'] === 12)->once();
});

it('measures the real database in megabytes', function () {
    $megabytes = app(DatabaseSize::class)->megabytes();

    expect($megabytes)->toBeFloat()->toBeGreaterThan(0.0);
});

it('shows the last measurement on System status for a super admin, and not before one exists', function () {
    $this->actingAs($this->super)->get('/admin')->assertOk()->assertSee('Database size')->assertSee('Not checked yet');

    databaseIs(900.0);
    $this->artisan('groibe:check-database-size')->assertSuccessful();

    asUser($this->super)->get('/admin')->assertOk()
        ->assertSee('900.0 MB of 1,000 MB (90%)')
        ->assertSee('over the warning level');
});

it('keeps the System status widget to super admins', function () {
    databaseIs(900.0);
    $this->artisan('groibe:check-database-size')->assertSuccessful();

    $this->actingAs($this->manager)->get('/admin')->assertOk()->assertDontSee('Database size')->assertDontSee('900.0 MB');
});
