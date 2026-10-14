<?php

namespace App\Auth;

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * Socialite driver for FDCP SSO (fdcp-accounts, Laravel Passport):
 * authorization code + PKCE, profile and app grants from /api/userinfo.
 */
class FdcpAccountsProvider extends AbstractProvider implements ProviderInterface
{
    protected $usesPKCE = true;

    public static function baseUrl(): string
    {
        return rtrim((string) config('services.fdcp_accounts.base_url'), '/');
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(self::baseUrl().'/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return self::baseUrl().'/oauth/token';
    }

    /** @return array<string, mixed> */
    protected function getUserByToken($token): array
    {
        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(10)
            ->get(self::baseUrl().'/api/userinfo')
            ->throw()
            ->json();
    }

    /** @param  array<string, mixed>  $user */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
        ]);
    }
}
