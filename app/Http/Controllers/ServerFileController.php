<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\ServerMonitor;
use Illuminate\Http\Request;

class ServerFileController extends Controller
{
    public function __construct(private ServerMonitor $monitor) {}

    /** Lista un directorio (JSON). */
    public function list(Request $request, Server $server)
    {
        $path = $request->query('path', '/');

        try {
            return response()->json(array_merge(['ok' => true], $this->monitor->listPath($server, $path)));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /** Lee un archivo (JSON). */
    public function read(Request $request, Server $server)
    {
        $request->validate(['path' => 'required|string']);

        try {
            return response()->json(array_merge(['ok' => true], $this->monitor->readFile($server, $request->query('path'))));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}
