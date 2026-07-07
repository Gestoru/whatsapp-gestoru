<?php

namespace Tests\Unit;

use App\Services\DomainInspector;
use App\Services\StressTester;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ServicesTest extends TestCase
{
    public function test_domain_apex_extraction(): void
    {
        $ins = new DomainInspector();

        $this->assertSame('gestoru.com', $ins->apexFor('app.gestoru.com'));
        $this->assertSame('gestoru.com', $ins->apexFor('www.gestoru.com'));
        $this->assertSame('gestoru.com', $ins->apexFor('gestoru.com'));
        $this->assertSame('miempresa.com.co', $ins->apexFor('tienda.miempresa.com.co'));
        $this->assertSame('dominio.co.uk', $ins->apexFor('panel.sub.dominio.co.uk'));
        $this->assertNull($ins->apexFor('10.0.0.1'));
        $this->assertNull($ins->apexFor('localhost'));
    }

    public function test_stress_parser_and_verdict(): void
    {
        $st = new StressTester(
            $this->createMock(\App\Services\SshClient::class),
            $this->createMock(\App\Services\ServerMonitor::class),
        );
        $m = new ReflectionMethod($st, 'parse');
        $m->setAccessible(true);

        // Sitio sano
        $ok = $m->invoke($st, "CLIENT=curl\nCPU=40\n==STATS==\ntotal=2000\navg_ms=120\nmax_ms=800\ncode=200:2000", 10, 20, 'https://x.com');
        $this->assertSame(2000, $ok['total']);
        $this->assertSame(0.0, $ok['error_pct']);
        $this->assertSame('ok', $ok['verdict']['level']);

        // Muchos 5xx
        $bad = $m->invoke($st, "CLIENT=curl\nCPU=95\n==STATS==\ntotal=1000\navg_ms=3000\nmax_ms=9000\ncode=200:600\ncode=502:400", 10, 30, 'https://x.com');
        $this->assertGreaterThanOrEqual(20, $bad['error_pct']);
        $this->assertSame('bad', $bad['verdict']['level']);

        // Sin cliente HTTP
        $noc = $m->invoke($st, "CLIENT=\n==STATS==", 10, 10, 'https://x.com');
        $this->assertSame('bad', $noc['verdict']['level']);
    }

    public function test_stress_allows_only_own_targets(): void
    {
        $targets = ['194.163.159.44', 'gestordepartes.net'];
        $check = function ($url) use ($targets) {
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
            foreach ($targets as $t) {
                $t = strtolower($t);
                if ($host === $t || $host === 'www.'.$t || str_ends_with($host, '.'.$t)) {
                    return true;
                }
            }
            return false;
        };

        $this->assertTrue($check('https://gestordepartes.net/'));
        $this->assertTrue($check('https://app.gestordepartes.net/'));
        $this->assertFalse($check('https://google.com/'));
    }
}
