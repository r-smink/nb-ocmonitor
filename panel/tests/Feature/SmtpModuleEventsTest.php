<?php

namespace Tests\Feature;

use App\Mail\AlertDigest;
use App\Models\Alert;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Ocm\Alerts\Alerts;
use App\Ocm\EventIngestor;
use App\Ocm\IssueActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Events en inventaris van de SMTP-module op de site (client-plugin 0.15.0+). */
class SmtpModuleEventsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:00:00');
        config(['ocm.alerts.delay_minutes' => 10, 'ocm.alerts.repeat_hours' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function site(array $smtp = ['enabled' => true, 'active' => true, 'reason' => null, 'host' => 'smtp.example.nl', 'port' => 587]): Site
    {
        $site = $this->makePairedSite();
        $site->last_seen_at = now();
        $site->uptime_state = 'up';
        $site->inventory = ['core' => ['version' => '6.9'], 'plugins' => [], 'themes' => [], 'smtp' => $smtp];
        $site->save();

        return $site;
    }

    private function smtpEvent(Site $site, string $type, int $minutesAgo, string $code = 'auth'): void
    {
        (new EventIngestor())->ingest($site, [[
            'seq' => ++$this->seq, 'time' => now()->subMinutes($minutesAgo)->getTimestamp(), 'type' => $type, 'object' => 'klant@example.nl',
            'result' => $type === 'smtp.failed' ? 'failed' : 'success', 'source' => 'unknown', 'user_login' => '',
            'data' => ['subject' => 'Bestelling', 'error' => 'SMTP Error: Could not authenticate.', 'diagnose_code' => $type === 'smtp.failed' ? $code : '', 'initiator' => 'WooCommerce', 'log_id' => 12, 'suppressed' => 0],
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private function smtpIssues(Site $site): array
    {
        return collect($site->fresh()->issues())->filter(fn ($i) => str_starts_with($i['key'], 'smtp-'))->values()->all();
    }

    public function test_failed_mail_is_a_critical_issue_with_a_link_until_recovered(): void
    {
        $site = $this->site();
        $this->smtpEvent($site, 'smtp.failed', 30);
        $this->smtpEvent($site, 'smtp.failed', 10);

        $issues = $this->smtpIssues($site);
        $this->assertCount(1, $issues);
        $this->assertSame('crit', $issues[0]['level']);
        $this->assertSame('beschikbaarheid', $issues[0]['category']);
        $this->assertSame('smtp-failed', $issues[0]['key']);
        $this->assertSame('E-mail versturen mislukt sinds 04-11 12:30: inloggen lukt niet', $issues[0]['text']); // eerste fout, Amsterdamse tijd
        $this->assertSame('https://klant.example.nl/wp-admin/admin.php?page=nb-ocmonitor&tab=maillog', $issues[0]['smtp']);

        $action = IssueActions::for($issues[0], $site, [], $this->makeUser());
        $this->assertSame(['kind' => 'link', 'label' => 'E-maillog', 'url' => $issues[0]['smtp']], $action);

        $this->actingAs($this->makeUser('viewer', true));
        $this->get('/')->assertOk()->assertSee('E-mail versturen mislukt sinds')->assertSee('tab=maillog', false);
        $this->get(route('sites.show', $site))->assertOk()->assertSee('E-mail versturen mislukt sinds')->assertSee('E-maillog in WP-admin');

        $this->smtpEvent($site, 'smtp.recovered', 1);
        $this->assertSame([], $this->smtpIssues($site));
    }

    public function test_backup_used_closes_the_problem_and_is_only_a_tip(): void
    {
        $site = $this->site();
        $this->smtpEvent($site, 'smtp.failed', 30);
        $this->smtpEvent($site, 'smtp.backup_used', 5);

        $issues = $this->smtpIssues($site);
        $this->assertCount(1, $issues);
        $this->assertSame('info', $issues[0]['level']);
        $this->assertStringContainsString('via de backupverbinding', $issues[0]['text']);

        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame([], $this->smtpIssues($site));
    }

    public function test_bad_recipient_is_only_a_warning(): void
    {
        $site = $this->site();
        $this->smtpEvent($site, 'smtp.failed', 5, 'recipient');

        $this->assertSame('warn', $this->smtpIssues($site)[0]['level']);
    }

    public function test_enabled_but_inactive_module_is_a_warning_and_disabled_module_ignores_old_failures(): void
    {
        $site = $this->site(['enabled' => true, 'active' => false, 'reason' => 'Nog niet ingesteld: vul server, poort en login in.']);
        $issues = $this->smtpIssues($site);
        $this->assertCount(1, $issues);
        $this->assertSame('warn', $issues[0]['level']);
        $this->assertSame('smtp-inactive', $issues[0]['key']);
        $this->assertStringContainsString('Nog niet ingesteld', $issues[0]['text']);

        $this->smtpEvent($site, 'smtp.failed', 5);
        $site->inventory = ['core' => ['version' => '6.9'], 'smtp' => ['enabled' => false]];
        $site->save();
        $this->assertSame([], $this->smtpIssues($site));

        // Oude client zonder SMTP-module: niets.
        $site->inventory = ['core' => ['version' => '6.9']];
        $site->save();
        SiteEvent::query()->delete();
        $this->assertSame([], $this->smtpIssues($site));
    }

    public function test_failed_mail_is_alerted_through_the_existing_alerts(): void
    {
        Mail::fake();
        $admin = $this->makeUser();
        $site = $this->site();
        Alerts::track(Site::query()->get()); // nulmeting
        Alerts::deliver();

        $this->smtpEvent($site, 'smtp.failed', 0);
        Carbon::setTestNow(now()->addMinutes(1));
        Alerts::track(Site::query()->get());
        Carbon::setTestNow(now()->addMinutes(15));
        Alerts::track(Site::query()->get());
        Alerts::deliver();

        Mail::assertSent(AlertDigest::class, fn (AlertDigest $m) => $m->hasTo($admin->email)
            && $m->items[0]['kind'] === 'open' && str_contains($m->items[0]['text'], 'E-mail versturen mislukt'));

        $this->smtpEvent($site, 'smtp.recovered', 0);
        Carbon::setTestNow(now()->addMinutes(5));
        Alerts::track(Site::query()->get());
        $this->assertNotNull(Alert::query()->where('key', 'smtp-failed')->first()->resolved_at);
    }

    public function test_smtp_event_types_have_dutch_labels(): void
    {
        $label = fn (string $type, string $result = 'success') => (new SiteEvent(['type' => $type, 'object' => '', 'result' => $result]))->summary();

        $this->assertSame('E-mail niet verstuurd (mislukt)', $label('smtp.failed', 'failed'));
        $this->assertSame('E-mail via backupverbinding verstuurd', $label('smtp.backup_used'));
        $this->assertSame('E-mail werkt weer', $label('smtp.recovered'));
        $this->assertSame('SMTP-instellingen gewijzigd', $label('ocm.smtp_changed'));
        $this->assertSame('SMTP-testmail', $label('ocm.smtp_test'));
        $this->assertSame('SMTP-backupverbinding gewijzigd', $label('ocm.smtp_backup_changed'));
    }
}
