<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\StressTester;
use Illuminate\Http\Request;

class StressController extends Controller
{
    public function __construct(private StressTester $tester) {}

    /** Formulario de prueba de estrés. */
    public function form(Server $server)
    {
        $targets = $server->hasCredentials() ? $this->tester->allowedTargets($server) : [];

        return view('dashboard.stress', [
            'server'  => $server,
            'targets' => $targets,
            'result'  => null,
            'error'   => $server->hasCredentials() ? null : 'Este servidor aún no tiene credenciales configuradas.',
        ]);
    }

    /** Ejecuta la prueba. */
    public function run(Request $request, Server $server)
    {
        $data = $request->validate([
            'url'         => 'required|url|max:500',
            'seconds'     => 'required|integer|min:3|max:'.StressTester::MAX_SECONDS,
            'concurrency' => 'required|integer|min:1|max:'.StressTester::MAX_CONCURRENCY,
        ]);

        $targets = $this->tester->allowedTargets($server);
        $result  = null;
        $error   = null;

        if (! $this->tester->isAllowed($server, $data['url'])) {
            $error = 'Por seguridad, solo puedes probar dominios/host de ESTE servidor: '.implode(', ', $targets);
        } else {
            try {
                $result = $this->tester->run($server, $data['url'], (int) $data['seconds'], (int) $data['concurrency']);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('dashboard.stress', compact('server', 'targets', 'result', 'error'));
    }
}
