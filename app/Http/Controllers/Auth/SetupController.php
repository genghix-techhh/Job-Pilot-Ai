<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Optional one-time owner creation in the browser. Disabled unless OWNER_SETUP_TOKEN is set
 * (so nobody who finds a fresh install can claim it), and permanently closed once an owner exists.
 */
class SetupController extends Controller
{
    private function guard(): void
    {
        abort_if(User::query()->exists() || blank(config('jobpilot.setup_token')), 404);
    }

    public function create(): View
    {
        $this->guard();

        return view('auth.setup', ['timezones' => timezone_identifiers_list()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard();

        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        if (! hash_equals((string) config('jobpilot.setup_token'), $data['token'])) {
            throw ValidationException::withMessages(['token' => 'That setup token is not correct.']);
        }

        $user = User::create([
            'name' => $data['name'], 'email' => $data['email'],
            'password' => $data['password'], 'timezone' => $data['timezone'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('privacy.consent')
            ->with('status', 'Owner created. Remove OWNER_SETUP_TOKEN from your .env now — the setup page is closed for good.');
    }
}
