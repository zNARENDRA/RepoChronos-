<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\PasswordByteLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function form(string $mode = 'login')
    {
        return view('auth', compact('mode'));
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => ['required', 'string', new PasswordByteLimit]]);
        if (! Auth::attempt($data)) {
            return back()->withErrors(['email' => 'Invalid login.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function register(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'string', new PasswordByteLimit, 'confirmed', Password::min(12)]]);
        $user = User::create($data);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
