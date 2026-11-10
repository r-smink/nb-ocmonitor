<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_is_set_normalized_and_shown_on_cards_and_detail(): void
    {
        $site = $this->makePairedSite('bakkerij.example.nl');
        $site->forceFill(['site_id' => 'site_bakkerij', 'name' => 'Bakkerij'])->save();
        $other = $this->makePairedSite('slager.example.nl');
        $other->forceFill(['site_id' => 'site_slager', 'name' => 'Slager'])->save();
        $this->actingAs($this->makeUser());

        $this->post("/sites/{$site->site_id}/server", ['server' => ' do3 '])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('DO3', $site->fresh()->server);
        $this->assertDatabaseHas('activity_log', ['action' => 'site_server_changed']);

        $this->post("/sites/{$other->site_id}/server", ['server' => 'DO1; rm -rf'])->assertSessionHasErrors('server');
        $this->assertNull($other->fresh()->server);

        $this->get('/sites')->assertOk()->assertSee('<span class="badge server" title="Server">DO3</span>', false);
        $this->get("/sites/{$site->site_id}")->assertOk()->assertSee('DO3')->assertSee('<option value="DO3">', false);

        $page = $this->get('/sites?indeling=server')->assertOk();
        $page->assertSeeInOrder(['DO3', 'Geen server ingevuld']);
        $this->get('/sites?q=do3')->assertOk()->assertSee('bakkerij.example.nl')->assertDontSee('slager.example.nl');

        $this->post("/sites/{$site->site_id}/server", ['server' => ''])->assertRedirect();
        $this->assertNull($site->fresh()->server);
    }

    public function test_server_can_be_given_when_creating_a_site(): void
    {
        $this->actingAs($this->makeUser());
        $this->post('/sites', ['name' => 'Bakkerij', 'url' => 'https://bakkerij.example.nl', 'server' => 'do6'])->assertRedirect();
        $this->assertSame('DO6', Site::query()->where('host', 'bakkerij.example.nl')->value('server'));
    }
}
