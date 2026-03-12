<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestUser
{
    private const SESSION_KEY = 'chat_guest_user_id';

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        $guestId = (int) $request->session()->get(self::SESSION_KEY, 0);

        if ($guestId > 0) {
            $guestUser = User::query()->find($guestId);

            if ($guestUser !== null) {
                Auth::login($guestUser);

                return $next($request);
            }
        }

        $guestUser = User::query()->create([
            'name' => 'Invitado ' . Str::upper(Str::random(4)),
            'email' => sprintf('guest_%s@chatify.local', Str::lower((string) Str::uuid())),
            'password' => Hash::make(Str::random(40)),
        ]);

        Auth::login($guestUser);
        $request->session()->put(self::SESSION_KEY, $guestUser->id);

        return $next($request);
    }
}

