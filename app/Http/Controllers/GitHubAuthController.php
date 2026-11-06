<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use RuntimeException;
use Throwable;

class GitHubAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $request->session()->forget('github_link_user_id');

        return $this->begin($request);
    }

    public function link(Request $request)
    {
        $request->session()->put('github_link_user_id', (string) $request->user()->id);

        return $this->begin($request);
    }

    private function configured(): bool
    {
        return filled(config('services.github.client_id')) && filled(config('services.github.client_secret'));
    }

    private function provider()
    {
        // Keep the stateful web flow, request identity/email only, and bind the code with PKCE.
        return Socialite::driver('github')->setScopes(['user:email'])->enablePKCE();
    }

    private function begin(Request $request)
    {
        if (! $this->configured()) {
            return $this->failure($request, 'GitHub sign-in is currently unavailable. Please use email and password.');
        }

        return $this->provider()->redirect();
    }

    public function callback(Request $request)
    {
        if (! $this->configured()) {
            return $this->failure($request, 'GitHub sign-in is currently unavailable. Please use email and password.');
        }
        if ($request->has('error')) {
            return $this->failure($request, 'GitHub authorization was cancelled. You can try again.');
        }

        $linkId = $request->session()->pull('github_link_user_id');
        if (($request->user() && (string) $request->user()->id !== $linkId) || (! $request->user() && $linkId !== null)) {
            return $this->failure($request, 'Your login session changed. Start GitHub sign-in again.');
        }

        try {
            $identity = $this->provider()->user();
            $githubId = (string) $identity->getId();
            if (! preg_match('/^[0-9]{1,30}$/', $githubId)) {
                throw new RuntimeException('Invalid GitHub identity.');
            }

            $user = DB::transaction(function () use ($request, $identity, $githubId, $linkId) {
                $linked = User::where('github_id', $githubId)->first();
                if ($linkId !== null) {
                    $current = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                    if (($linked && $linked->id !== $current->id) || ($current->github_id !== null && $current->github_id !== $githubId)) {
                        throw new RuntimeException('This GitHub account or local account is already linked.');
                    }
                    $current->forceFill(['github_id' => $githubId])->save();

                    return $current;
                }
                if ($linked) {
                    return $linked;
                }

                // Socialite's GitHub provider returns only a primary, verified email
                // when user:email is requested. Never merge an existing account by email.
                $email = $identity->getEmail();
                if (! is_string($email) || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('A verified primary GitHub email is required.');
                }
                if (User::whereRaw('lower(email) = ?', [strtolower($email)])->exists()) {
                    throw new RuntimeException('Log in with your existing password, then select Link GitHub in your workspace.');
                }
                $name = mb_substr(trim($identity->getName() ?: $identity->getNickname() ?: 'GitHub user'), 0, 80);
                $user = new User;
                $user->forceFill(['name' => $name, 'email' => $email, 'email_verified_at' => now(),
                    'password' => Str::random(64), 'github_id' => $githubId])->save();

                return $user;
            });
        } catch (InvalidStateException $error) {
            return $this->failure($request, 'GitHub sign-in expired or could not be verified. Please start again.');
        } catch (QueryException $error) {
            // Unique email/identity constraints also protect simultaneous callbacks.
            return $this->failure($request, 'The account could not be saved. Please try GitHub sign-in again.');
        } catch (RuntimeException $error) {
            // Only the messages deliberately raised here are shown. Provider errors may contain tokens.
            $safe = ['This GitHub account or local account is already linked.',
                'A verified primary GitHub email is required.',
                'Log in with your existing password, then select Link GitHub in your workspace.'];

            return $this->failure($request, in_array($error->getMessage(), $safe, true)
                ? $error->getMessage() : 'GitHub sign-in could not finish. Please try again.');
        } catch (Throwable $error) {
            return $this->failure($request, 'GitHub sign-in could not finish. Please try again.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['state', 'code_verifier']);

        return $linkId !== null
            ? redirect('/')->with('notice', 'GitHub linked. You can now use Continue with GitHub to log in.')
            : redirect()->intended('/');
    }

    private function failure(Request $request, string $message)
    {
        $request->session()->forget(['github_link_user_id', 'state', 'code_verifier']);

        return redirect($request->user() ? '/' : '/login')->withErrors(['github' => $message]);
    }
}
