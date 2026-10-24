<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AdminMagicLinkNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Passwordless entry for super admins: request a link, click it, get logged
 * in. Separate from the normal /auth/* flow on purpose -- a super admin
 * account can still log in with a password too (it's just a flag on the
 * same User), this is an additional, faster path only for is_super_admin.
 */
class AuthController extends Controller
{
    public function requestMagicLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::query()
            ->where('email', $request->string('email'))
            ->where('is_super_admin', true)
            ->first();

        if ($user) {
            $url = URL::temporarySignedRoute(
                'admin.magic-login',
                now()->addMinutes(15),
                ['id' => $user->id, 'hash' => sha1($user->email)],
            );

            $user->notify(new AdminMagicLinkNotification($url));
        }

        // Generic response regardless -- never reveal who is a super admin.
        return response()->json([
            'message' => 'Si el correo corresponde a un administrador, le enviamos un enlace de acceso.',
        ]);
    }

    public function magicLogin(string $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $user = User::query()->find($id);

        if (! $user || ! $user->is_super_admin || ! hash_equals(sha1($user->email), $hash)) {
            return redirect()->away($frontendUrl.'/admin/login-callback?ok=0');
        }

        $token = $user->createToken('admin-magic-login')->plainTextToken;

        return redirect()->away($frontendUrl.'/admin/login-callback?ok=1&token='.$token);
    }
}
