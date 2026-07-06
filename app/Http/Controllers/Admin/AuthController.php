<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (session('server_admin_authed')) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login', [
            'notConfigured' => empty(config('servers.admin_password')),
        ]);
    }

    public function login(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $expected = config('servers.admin_password');

        if (empty($expected)) {
            return back()->withErrors([
                'password' => 'El panel no está configurado. Define SERVER_ADMIN_PASSWORD en el archivo .env.',
            ]);
        }

        // Acepta contraseña en texto plano o un hash bcrypt en .env.
        $ok = str_starts_with($expected, '$2y$')
            ? Hash::check($request->password, $expected)
            : hash_equals($expected, $request->password);

        if (! $ok) {
            return back()->withErrors(['password' => 'Contraseña incorrecta.']);
        }

        $request->session()->regenerate();
        $request->session()->put('server_admin_authed', true);

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('server_admin_authed');
        $request->session()->regenerate();

        return redirect()->route('admin.login');
    }
}
