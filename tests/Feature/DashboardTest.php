<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_panel(): void
    {
        $this->get('/')->assertRedirect('/panel');
    }

    public function test_overview_loads(): void
    {
        Server::create(['name' => 'Demo', 'provider' => 'contabo', 'host' => '1.2.3.4', 'username' => 'root']);

        $this->get('/panel')->assertOk()->assertSee('Demo');
    }

    public function test_key_pages_load(): void
    {
        $this->get('/panel/servidores/nuevo')->assertOk();
        $this->get('/panel/dominios')->assertOk();
        $this->get('/panel/alertas')->assertOk();
    }

    public function test_creating_a_server_encrypts_the_password(): void
    {
        $this->post('/panel/servidores', [
            'name' => 'Servidor X', 'provider' => 'contabo', 'host' => '9.9.9.9',
            'port' => 22, 'username' => 'root', 'auth_type' => 'password',
            'password' => 'supersecreta',
        ])->assertRedirect();

        $server = Server::firstWhere('host', '9.9.9.9');
        $this->assertNotNull($server);
        $this->assertSame('supersecreta', $server->password); // se descifra
        // En crudo NO debe verse la contraseña
        $raw = DB::table('servers')->where('host', '9.9.9.9')->value('password');
        $this->assertStringNotContainsString('supersecreta', $raw);
    }

    public function test_metrics_endpoint_reports_missing_credentials(): void
    {
        $server = Server::create(['name' => 'SinClave', 'provider' => 'contabo', 'host' => '5.5.5.5', 'username' => 'root', 'auth_type' => 'password']);

        $this->getJson("/panel/servidores/{$server->id}/metricas")
            ->assertOk()
            ->assertJson(['ok' => false, 'needs_credentials' => true]);
    }

    public function test_server_detail_without_credentials_shows_prompt(): void
    {
        $server = Server::create(['name' => 'SinClave2', 'provider' => 'contabo', 'host' => '6.6.6.6', 'username' => 'root', 'auth_type' => 'password']);

        $this->get("/panel/servidores/{$server->id}")
            ->assertOk()
            ->assertSee('Poner contraseña ahora');
    }

    public function test_settings_store_and_read(): void
    {
        Setting::put('alert_cpu', '77');
        $this->assertSame('77', Setting::get('alert_cpu'));
        $this->assertTrue(Setting::boolean('x', true));
    }

    public function test_domain_models_expiry_levels(): void
    {
        $due = Domain::create(['name' => 'a.com', 'registrar' => 'godaddy', 'expires_at' => now()->addDays(5)]);
        $warn = Domain::create(['name' => 'b.com', 'registrar' => 'godaddy', 'expires_at' => now()->addDays(20)]);
        $ok = Domain::create(['name' => 'c.com', 'registrar' => 'godaddy', 'expires_at' => now()->addDays(100)]);

        $this->assertSame('due', $due->expiryLevel());
        $this->assertSame('warn', $warn->expiryLevel());
        $this->assertSame('ok', $ok->expiryLevel());
    }

    public function test_server_payment_levels(): void
    {
        $s = Server::create(['name' => 'P', 'provider' => 'contabo', 'host' => '7.7.7.7', 'username' => 'root', 'paid_until' => now()->addDays(3)]);
        $this->assertSame('due', $s->paymentLevel());
        $this->assertStringContainsString('contabo.com', $s->renewalLink());
    }

    public function test_godaddy_credentials_are_encrypted(): void
    {
        \App\Services\GoDaddyService::saveCredentials('KEY123', 'SECRET456');

        $raw = Setting::where('key', 'godaddy_api_secret')->value('value');
        $this->assertStringNotContainsString('SECRET456', $raw);
        $this->assertTrue(app(\App\Services\GoDaddyService::class)->configured());
    }

    public function test_contabo_next_renewal_is_in_the_future(): void
    {
        \App\Services\ContaboService::saveCredentials('cid', 'sec', 'a@b.com', 'pass');
        $svc = app(\App\Services\ContaboService::class);
        $m = new \ReflectionMethod($svc, 'nextRenewal');
        $m->setAccessible(true);

        $date = $m->invoke($svc, '2024-10-25T00:00:00Z');
        $this->assertGreaterThanOrEqual(now()->startOfDay()->format('Y-m-d'), $date);
        $this->assertSame('25', substr($date, 8, 2)); // conserva el día 25
    }
}
