<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\SiteNote;
use App\Ocm\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotesExportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_can_be_added_pinned_and_are_shown_on_cards_and_updates(): void
    {
        $site = $this->makePairedSite();
        $site->inventory = ['plugins' => [['file' => 'a/a.php', 'slug' => 'a', 'name' => 'A', 'version' => '1', 'update' => ['version' => '2']]]];
        $site->save();
        $this->actingAs($this->makeUser('beheerder'));

        $this->post('/sites/' . $site->site_id . '/notities', ['body' => 'Klant wil in december geen updates', 'pinned' => 1])->assertRedirect();
        $this->post('/sites/' . $site->site_id . '/notities', ['body' => 'Hosting bij TransIP'])->assertRedirect();
        $this->assertSame(2, SiteNote::query()->count());

        $this->get('/sites/' . $site->site_id)->assertOk()->assertSeeInOrder(['Klant wil in december geen updates', 'Hosting bij TransIP']);
        $this->get('/sites')->assertOk()->assertSee('Klant wil in december geen updates')->assertDontSee('Hosting bij TransIP');
        $this->get('/updates')->assertOk()->assertSee('Klant wil in december geen updates');

        $note = SiteNote::query()->where('pinned', true)->first();
        $this->post('/sites/' . $site->site_id . '/notities/' . $note->id . '/vastzetten')->assertRedirect();
        $this->assertFalse($note->fresh()->pinned);
        $this->delete('/sites/' . $site->site_id . '/notities/' . $note->id)->assertRedirect();
        $this->assertSame(1, SiteNote::query()->count());
    }

    public function test_viewer_cannot_add_notes_and_others_cannot_delete_foreign_notes(): void
    {
        $site = $this->makePairedSite();
        $author = $this->makeUser('admin');
        $note = $site->notes()->create(['body' => 'x', 'user_id' => $author->id]);

        $this->actingAs($this->makeUser('beheerder'));
        $this->delete('/sites/' . $site->site_id . '/notities/' . $note->id)->assertForbidden();
        $this->assertSame(1, SiteNote::query()->count());
    }

    public function test_viewer_cannot_post_notes(): void
    {
        $site = $this->makePairedSite();
        $this->actingAs($this->makeUser('viewer'));
        $this->post('/sites/' . $site->site_id . '/notities', ['body' => 'x'])->assertForbidden();
    }

    public function test_audit_export_is_admin_only_csv_with_hash_chain(): void
    {
        $admin = $this->makeUser();
        $this->actingAs($admin);
        Activity::log('test_action', ['note' => '=HYPERLINK("x")']);

        $response = $this->get('/activiteit/export?tab=audit');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('prev_hash,hash', $csv);
        $this->assertStringContainsString('test_action', $csv);
        $this->assertStringContainsString(ActivityLog::query()->where('action', 'test_action')->value('hash'), $csv);
        $this->assertTrue(ActivityLog::query()->where('action', 'activity_exported')->exists());

        $this->get('/activiteit/export?tab=sites')->assertOk();
    }

    public function test_viewer_can_export_site_changes_but_not_the_audit_log(): void
    {
        $this->actingAs($this->makeUser('viewer'));
        $this->get('/activiteit/export?tab=audit')->assertForbidden();
        $this->get('/activiteit/export?tab=sites&van=2026-01-01&tot=2026-02-01')->assertOk();
    }

    public function test_api_requires_a_valid_token_and_returns_site_data_without_secrets(): void
    {
        $site = $this->makePairedSite();
        $site->forceFill(['wp_version' => '6.9', 'last_seen_at' => now()])->save();
        [, $plain] = ApiToken::issue('Grafana', null);

        $this->getJson('/api/v1/sites')->assertStatus(401);
        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer ocm_fout'])->assertStatus(401);

        $res = $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer ' . $plain])->assertOk();
        $res->assertJsonPath('data.0.site_id', 'site_test01')->assertJsonPath('data.0.wordpress', '6.9');
        $this->assertStringNotContainsString($site->public_key, $res->getContent());

        $this->getJson('/api/v1/sites/site_test01', ['Authorization' => 'Bearer ' . $plain])->assertOk()->assertJsonStructure(['data' => ['issues', 'updates', 'uptime_30d']]);
        $this->getJson('/api/v1/updates', ['Authorization' => 'Bearer ' . $plain])->assertOk();
        $this->assertNotNull(ApiToken::query()->first()->last_used_at);
    }

    public function test_revoked_and_expired_tokens_are_rejected(): void
    {
        [$revoked, $a] = ApiToken::issue('a', null);
        $revoked->forceFill(['revoked_at' => now()])->save();
        [, $b] = ApiToken::issue('b', null, now()->subDay());

        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer ' . $a])->assertStatus(401);
        $this->getJson('/api/v1/sites', ['Authorization' => 'Bearer ' . $b])->assertStatus(401);
    }

    public function test_admin_creates_token_once_and_revokes_it(): void
    {
        $this->actingAs($this->makeUser());
        $this->post('/instellingen/api', ['name' => 'Facturatie', 'expires' => '90'])->assertRedirect('/instellingen/api');
        $token = ApiToken::query()->sole();
        $page = $this->get('/instellingen/api')->assertOk()->assertSee('Facturatie')->assertSee('id="newToken"', false);
        preg_match('/id="newToken"[^>]*>(ocm_[A-Za-z0-9]+)</', $page->getContent(), $m);
        $this->assertSame($token->token_hash, hash('sha256', $m[1]));
        $this->get('/instellingen/api')->assertOk()->assertDontSee('id="newToken"', false);

        $this->delete('/instellingen/api/' . $token->id)->assertRedirect();
        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_viewer_cannot_manage_tokens(): void
    {
        $this->actingAs($this->makeUser('viewer'));
        $this->get('/instellingen/api')->assertForbidden();
        $this->post('/instellingen/api', ['name' => 'x'])->assertForbidden();
    }
}
