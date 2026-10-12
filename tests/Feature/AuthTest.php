<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\JobPilotHelpers;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use JobPilotHelpers, RefreshDatabase;

    public function test_no_default_owner_or_password_ships_with_the_app(): void
    {
        $this->assertSame(0, User::count());
        $this->artisan('db:seed')->assertSuccessful();
        $this->assertSame(0, User::count());
    }

    public function test_public_registration_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.test', 'password' => 'password-password'])->assertStatus(404);
        $this->assertSame(0, User::count());
        $this->owner();
        $this->get('/register')->assertNotFound();
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/dashboard', '/jobs', '/profile', '/applications', '/sources', '/settings', '/inbox', '/interviews', '/activity'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_login_page_explains_how_to_create_the_owner_when_none_exists(): void
    {
        $this->get('/login')->assertOk()->assertSee('php artisan app:create-owner');
    }

    public function test_owner_can_sign_in_and_out(): void
    {
        $this->owner();
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'correct-horse-battery'])->assertRedirect();
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_credentials_give_one_generic_message(): void
    {
        $this->owner();
        $a = $this->from('/login')->post('/login', ['email' => 'owner@example.test', 'password' => 'nope'])->assertSessionHasErrors('email');
        $b = $this->from('/login')->post('/login', ['email' => 'nobody@example.test', 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertSame(session('errors')->first('email'), 'These credentials do not match our records.');
        $this->assertGuest();
    }

    public function test_login_is_throttled(): void
    {
        $this->owner();
        RateLimiter::clear('x');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'.$i]);
        }
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'correct-horse-battery'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_setup_page_is_closed_without_a_token_or_once_an_owner_exists(): void
    {
        config(['jobpilot.setup_token' => null]);
        $this->get('/setup')->assertNotFound();

        config(['jobpilot.setup_token' => 'tok-123']);
        $this->get('/setup')->assertOk();
        $this->owner();
        $this->get('/setup')->assertNotFound();
        $this->post('/setup', [])->assertNotFound();
    }

    public function test_setup_creates_the_owner_only_with_the_right_token(): void
    {
        config(['jobpilot.setup_token' => 'tok-123']);
        $payload = ['name' => 'Me', 'email' => 'me@example.test', 'password' => 'a-very-long-password', 'password_confirmation' => 'a-very-long-password', 'timezone' => 'Asia/Karachi'];

        $this->post('/setup', $payload + ['token' => 'wrong'])->assertSessionHasErrors('token');
        $this->assertSame(0, User::count());

        $this->post('/setup', $payload + ['token' => 'tok-123'])->assertRedirect(route('privacy.consent'));
        $this->assertSame(1, User::count());
        $this->assertNotSame('a-very-long-password', User::first()->password, 'password must be hashed');
    }

    public function test_setup_enforces_a_strong_password(): void
    {
        config(['jobpilot.setup_token' => 'tok-123']);
        $this->post('/setup', ['token' => 'tok-123', 'name' => 'Me', 'email' => 'me@example.test', 'password' => 'short', 'password_confirmation' => 'short', 'timezone' => 'UTC'])
            ->assertSessionHasErrors('password');
        $this->assertSame(0, User::count());
    }

    public function test_create_owner_command_works_once_and_refuses_a_second_owner(): void
    {
        putenv('JOBPILOT_OWNER_PASSWORD=long-enough-password');
        $this->artisan('app:create-owner', ['--name' => 'Me', '--email' => 'me@example.test', '--no-interaction' => true])->assertSuccessful();
        $this->assertSame(1, User::count());
        $this->artisan('app:create-owner', ['--name' => 'Two', '--email' => 'two@example.test', '--no-interaction' => true])->assertFailed();
        $this->assertSame(1, User::count());
        putenv('JOBPILOT_OWNER_PASSWORD');
    }

    public function test_create_owner_command_rejects_weak_passwords_with_a_nonzero_exit(): void
    {
        putenv('JOBPILOT_OWNER_PASSWORD=short');
        $this->artisan('app:create-owner', ['--name' => 'Me', '--email' => 'me@example.test', '--no-interaction' => true])->assertFailed();
        $this->assertSame(0, User::count());
        putenv('JOBPILOT_OWNER_PASSWORD');
    }

    public function test_reset_password_command(): void
    {
        $u = $this->owner();
        putenv('JOBPILOT_OWNER_PASSWORD=brand-new-password-1');
        $this->artisan('app:reset-owner-password', ['--no-interaction' => true])->assertSuccessful();
        putenv('JOBPILOT_OWNER_PASSWORD');
        $this->post('/login', ['email' => $u->email, 'password' => 'brand-new-password-1'])->assertRedirect();
        $this->assertAuthenticated();
    }

    // ---- first-use privacy consent --------------------------------------------------------------

    public function test_first_use_redirects_to_the_consent_screen_until_a_choice_is_made(): void
    {
        $u = $this->owner();
        $this->actingAs($u)->get('/dashboard')->assertRedirect(route('privacy.consent'));
        $this->actingAs($u)->get('/privacy/consent')->assertOk()->assertSee('Allow redacted AI analysis')->assertSee('No thanks');
    }

    public function test_declining_ai_keeps_the_rest_of_the_app_usable(): void
    {
        $u = $this->owner();
        $this->actingAs($u)->post('/privacy/consent', ['decision' => 'decline'])->assertRedirect();

        $profile = $u->refresh()->profile;
        $this->assertFalse($profile->ai_consent);
        $this->assertFalse($profile->aiConsentGiven());
        foreach (['/dashboard', '/jobs', '/profile', '/sources', '/settings'] as $p) {
            $this->actingAs($u)->get($p)->assertOk();
        }
        $this->assertFalse(app(AiProvider::class)->availability()->available);
    }

    public function test_accepting_records_the_policy_version(): void
    {
        $u = $this->owner();
        $this->actingAs($u)->post('/privacy/consent', ['decision' => 'accept']);
        $p = $u->refresh()->profile;
        $this->assertTrue($p->ai_consent);
        $this->assertSame(config('jobpilot.privacy_version'), $p->privacy_consent_version);
    }

    public function test_privacy_page_is_public_and_explains_free_tier_data_use(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('human')->assertSee('cannot delete anything held by Google');
    }

    public function test_security_headers_are_present(): void
    {
        $r = $this->get('/login');
        $r->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
