<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ZenHrOAuthService
{
    private const TABLE = 'adms.zenhr_connections';

    public function isProvisioned(): bool
    {
        $r = DB::connection('sqlsrv')->selectOne(
            "SELECT OBJECT_ID(N'adms.zenhr_connections', N'U') AS table_id"
        );

        return $r !== null && $r->table_id !== null;
    }

    public function connectionInfo(): ?object
    {
        if (! $this->isProvisioned()) {
            return null;
        }

        return DB::connection('sqlsrv')->table(self::TABLE)
            ->where('id', 1)
            ->first([
                'company_id', 'authorized_scopes', 'token_expires_at',
                'last_verified_at', 'updated_at',
            ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) config('zenhr.redirect_uri'),
            'client_id' => (string) config('zenhr.client_id'),
            'client_secret' => (string) config('zenhr.client_secret'),
        ];

        $response = Http::asForm()->acceptJson()->timeout(20)
            ->post((string) config('zenhr.token_url'), $body);

        if (! $response->successful()) {
            throw new RuntimeException(
                'ZenHR authorization-code exchange failed (HTTP '.$response->status().').'
            );
        }

        $data = $response->json();
        if (! is_array($data)
            || ! is_string($data['access_token'] ?? null)
            || trim($data['access_token']) === '') {
            throw new RuntimeException('ZenHR token response did not include an access token.');
        }

        return $data;
    }

    public function validateAccessToken(string $token): array
    {
        $response = Http::acceptJson()->withToken($token)->timeout(15)
            ->get(rtrim((string) config('zenhr.api_base'), '/').'/who_am_i');

        if (! $response->successful()) {
            throw new RuntimeException(
                'ZenHR token validation failed (HTTP '.$response->status().').'
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('ZenHR verification response was not JSON.');
        }

        return $body;
    }

    /** Stores ONLY encrypted credentials in the ADMS operational schema. */
    public function storeConnection(array $tokenData, array $identity, int $adminId): void
    {
        if (! $this->isProvisioned()) {
            throw new RuntimeException('The encrypted ADMS ZenHR connection table has not been provisioned.');
        }

        $accessToken = (string) $tokenData['access_token'];
        $refreshToken = trim((string) ($tokenData['refresh_token'] ?? ''));
        $seconds = (int) ($tokenData['expires_in'] ?? 3600);
        $seconds = max(60, min($seconds, 365 * 24 * 3600));
        $scopes = $identity['token_info']['scopes'] ?? [];
        $companyId = $identity['token_info']['props']['company_id'] ?? null;
        $now = now('UTC')->format('Y-m-d H:i:s');

        DB::connection('sqlsrv')->transaction(function () use (
            $accessToken, $refreshToken, $seconds, $scopes, $companyId, $now, $adminId
        ): void {
            $db = DB::connection('sqlsrv');
            $previous = $db->table(self::TABLE)->where('id', 1)->first(['refresh_token_encrypted']);
            $encryptedRefresh = $refreshToken !== ''
                ? Crypt::encryptString($refreshToken)
                : ($previous?->refresh_token_encrypted ?? null);

            $data = [
                'access_token_encrypted' => Crypt::encryptString($accessToken),
                'refresh_token_encrypted' => $encryptedRefresh,
                'token_expires_at' => Carbon::now('UTC')->addSeconds($seconds)
                    ->format('Y-m-d H:i:s'),
                'company_id' => is_numeric($companyId) ? (int) $companyId : null,
                'authorized_scopes' => is_array($scopes) ? implode(' ', $scopes) : null,
                'connected_by' => $adminId,
                'last_verified_at' => $now,
                'updated_at' => $now,
            ];

            if ($previous !== null) {
                $db->table(self::TABLE)->where('id', 1)->update($data);
            } else {
                $db->table(self::TABLE)->insert(array_merge($data, [
                    'id' => 1,
                    'created_at' => $now,
                ]));
            }
        }, 1);
    }

    public function testStoredConnection(): array
    {
        if (! $this->isProvisioned()) {
            throw new RuntimeException('OAuth storage table is not ready.');
        }

        $connection = DB::connection('sqlsrv')->table(self::TABLE)
            ->where('id', 1)->first(['access_token_encrypted', 'token_expires_at']);

        if (! $connection) {
            throw new RuntimeException('ZenHR has not been authorized.');
        }

        if ($connection->token_expires_at &&
            Carbon::parse($connection->token_expires_at, 'UTC')->isPast()) {
            throw new RuntimeException('ZenHR access token has expired; reconnect to renew.');
        }

        return $this->validateAccessToken(
            Crypt::decryptString($connection->access_token_encrypted)
        );
    }
}
