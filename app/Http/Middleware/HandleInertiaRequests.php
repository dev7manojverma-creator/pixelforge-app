<?php

namespace App\Http\Middleware;

use App\Services\Credits;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => fn () => $user ? [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'is_admin' => $user->isAdmin()],
            ] : null,
            'credits' => fn () => $user ? $this->credits($user->fresh()) : null,
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    private function credits($user): array
    {
        $reserved = app(Credits::class)->reservedNano($user);

        return [
            'balance_nano' => $user->balance_nano,
            'reserved_nano' => $reserved,
            'available_nano' => $user->balance_nano - $reserved,
        ];
    }
}
