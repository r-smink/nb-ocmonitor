<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\UpdateQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateQueueTest extends TestCase
{
    use RefreshDatabase;

    private function job(Site $site, string $command, array $args, string $status, array $extra = []): SiteJob
    {
        return SiteJob::query()->create(array_merge([
            'job_id' => 'job_' . uniqid(), 'site_id' => $site->id, 'command' => $command, 'args' => $args,
            'status' => $status, 'available_at' => now(),
        ], $extra));
    }

    private function site(string $host, ?Customer $customer): Site
    {
        $site = $this->makePairedSite($host);
        $site->forceFill(['site_id' => 'site_' . str_replace('.', '', $host), 'name' => $host, 'customer_id' => $customer?->id, 'inventory' => ['plugins' => [
            ['file' => 'a/a.php', 'slug' => 'a', 'name' => 'Plugin A'],
            ['file' => 'b/b.php', 'slug' => 'b', 'name' => 'Plugin B'],
        ]]])->save();

        return $site;
    }

    public function test_queue_shows_progress_overall_and_per_customer(): void
    {
        $customer = Customer::query()->create(['name' => 'Bakkerij', 'email' => 'a@bakkerij.example', 'visits_per_year' => 4]);
        $one = $this->site('een.example.nl', $customer);
        $two = $this->site('twee.example.nl', null);

        $this->job($one, 'update.plugin', ['plugins' => ['a/a.php', 'b/b.php']], 'done', ['result' => ['items' => [
            ['id' => 'a/a.php', 'status' => 'updated', 'from' => '1.0', 'to' => '1.1'],
            ['id' => 'b/b.php', 'status' => 'rolled_back', 'message' => 'site gaf 500'],
        ]], 'finished_at' => now()]);
        $this->job($one, 'update.theme', ['themes' => ['astra']], 'running', ['started_at' => now()]);
        $held = $this->job($two, 'update.plugin', ['plugins' => ['a/a.php']], 'pending', ['available_at' => now()->addDay()]);
        Backup::query()->create(['backup_id' => 'bk1', 'site_id' => $two->id, 'status' => 'pending', 'components' => [], 'update_job_ids' => [$held->id]]);
        $this->job($two, 'update.core', [], 'pending');

        $overview = UpdateQueue::overview();
        $this->assertSame(5, $overview['totals']['total']);
        $this->assertSame(1, $overview['totals']['ok']);
        $this->assertSame(1, $overview['totals']['bad']);
        $this->assertSame(1, $overview['totals']['running']);
        $this->assertSame(2, $overview['totals']['waiting']);
        $this->assertSame(40, $overview['totals']['percent']);
        $this->assertSame(1, $overview['totals']['phase_backup_wait']);
        $this->assertSame(1, $overview['totals']['phase_partial']);
        $this->assertSame('Bakkerij', $overview['groups'][0]['customer']->name);

        $this->actingAs($this->makeUser());
        $this->get('/updates/wachtrij')->assertOk()
            ->assertSee('Totaal')->assertSee('Bakkerij')->assertSee('Sites zonder klant')
            ->assertSee('Plugin A')->assertSee('1.0 → 1.1')->assertSee('teruggedraaid')->assertSee('site gaf 500')
            ->assertSee('wacht op backup')->assertSee('deels mislukt')->assertSee('bezig');
        $this->get('/updates/wachtrij?klant=' . $customer->id)->assertOk()->assertSee('Bakkerij')->assertDontSee('Sites zonder klant');
        $this->get('/updates')->assertSee('Bekijk de voortgang in de wachtrij');
    }

    public function test_checked_backups_can_be_cleared_per_site_and_per_customer(): void
    {
        Storage::fake('backups');
        $customer = Customer::query()->create(['name' => 'Bakkerij', 'email' => 'a@bakkerij.example', 'visits_per_year' => 4]);
        $site = $this->site('een.example.nl', $customer);
        $other = $this->site('twee.example.nl', $customer);
        $done = $this->job($site, 'update.plugin', ['plugins' => ['a/a.php']], 'done');
        $open = $this->job($site, 'update.plugin', ['plugins' => ['b/b.php']], 'pending');
        $b1 = Backup::query()->create(['backup_id' => 'bk1', 'site_id' => $site->id, 'status' => 'ready', 'components' => ['plugins' => ['a/a.php']], 'update_job_ids' => [$done->id], 'expires_at' => now()->addDays(7)]);
        $b2 = Backup::query()->create(['backup_id' => 'bk2', 'site_id' => $site->id, 'status' => 'ready', 'components' => ['plugins' => ['a/a.php']], 'update_job_ids' => [$done->id], 'expires_at' => now()->addDays(7)]);
        $busy = Backup::query()->create(['backup_id' => 'bk3', 'site_id' => $site->id, 'status' => 'ready', 'components' => ['plugins' => ['b/b.php']], 'update_job_ids' => [$open->id], 'expires_at' => now()->addDays(7)]);
        $b4 = Backup::query()->create(['backup_id' => 'bk4', 'site_id' => $other->id, 'status' => 'ready', 'components' => [], 'update_job_ids' => [], 'expires_at' => now()->addDays(7)]);

        $this->actingAs($this->makeUser());
        $this->get('/sites/' . $site->site_id)->assertSee('Alles werkt: 2 back-ups opruimen');
        $this->post("/sites/{$site->site_id}/backups/opruimen")->assertRedirect()->assertSessionHas('status', '2 back-up(s) verwijderd.');
        $this->assertSame('deleted', $b1->fresh()->status);
        $this->assertSame('deleted', $b2->fresh()->status);
        $this->assertSame('ready', $busy->fresh()->status, 'back-up van een lopende update blijft staan');
        $this->assertSame('ready', $b4->fresh()->status);

        $this->post("/klanten/{$customer->id}/backups/opruimen")->assertSessionHas('status', '1 back-up(s) van Bakkerij verwijderd.');
        $this->assertSame('deleted', $b4->fresh()->status);
    }

    public function test_viewers_cannot_clear_backups(): void
    {
        $site = $this->site('een.example.nl', null);
        $this->actingAs($this->makeUser('viewer'))->post("/sites/{$site->site_id}/backups/opruimen")->assertForbidden();
        $this->get('/updates/wachtrij')->assertOk();
    }
}
