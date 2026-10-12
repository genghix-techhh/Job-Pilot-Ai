<?php

namespace App\Http\Middleware;

use App\Models\CandidateProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-use privacy screen: until the owner has either accepted or declined free-tier AI
 * processing, they are sent to the consent page. Declining is a valid choice — everything
 * except Gemini keeps working.
 */
class RequirePrivacyDecision
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $request->routeIs('privacy.*', 'logout')) {
            $profile = CandidateProfile::query()->where('user_id', $user->id)->first();
            if (! $profile || $profile->ai_consent === null) {
                return redirect()->route('privacy.consent');
            }
        }

        return $next($request);
    }
}
