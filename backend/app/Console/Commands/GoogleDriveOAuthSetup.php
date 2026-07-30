<?php

namespace App\Console\Commands;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Console\Command;

/**
 * One-time bootstrap for GOOGLE_DRIVE_AUTH_MODE=oauth: exchanges a Desktop-app
 * OAuth consent for a refresh token, so GoogleDriveService can act as your own
 * Google account instead of the quota-less service account.
 *
 * Desktop-app clients redirect to a loopback address (RFC 8252) — Google
 * removed the old copy/paste "out of band" flow in 2022 — so there's no
 * Laravel route to catch it; the app isn't running. This opens a throwaway
 * raw socket just long enough to read that one redirect.
 */
class GoogleDriveOAuthSetup extends Command
{
    protected $signature = 'google-drive:oauth-setup {--port=8976 : Loopback port Google redirects back to}';

    protected $description = 'One-time OAuth flow to mint a Google Drive refresh token for local/dev use';

    public function handle(): int
    {
        $clientId = config('services.google_drive.oauth_client_id');
        $clientSecret = config('services.google_drive.oauth_client_secret');

        if (! $clientId || ! $clientSecret) {
            $this->error(
                'Set GOOGLE_DRIVE_OAUTH_CLIENT_ID and GOOGLE_DRIVE_OAUTH_CLIENT_SECRET in .env '
                .'first (see the Cloud Console steps you were given), then re-run this command.'
            );

            return self::FAILURE;
        }

        $port = (int) $this->option('port');
        $redirectUri = "http://127.0.0.1:{$port}";

        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->addScope(Drive::DRIVE_FILE);

        // Both are required to actually get a refresh_token back: Google only
        // issues one the very first time an app is authorized unless you force
        // it with prompt=consent, and access_type=offline is what makes it
        // usable without the user in the loop afterwards.
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $this->info('Open this URL and sign in with the Google account uploads should run as:');
        $this->newLine();
        $this->line($client->createAuthUrl());
        $this->newLine();
        $this->info("Waiting for the redirect to {$redirectUri} ...");

        $code = $this->captureAuthorizationCode($port);

        if ($code === null) {
            return self::FAILURE;
        }

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            $this->error('Google rejected the code: '.($token['error_description'] ?? $token['error']));

            return self::FAILURE;
        }

        if (empty($token['refresh_token'])) {
            $this->error(
                'No refresh_token came back. This happens when this Google account already '
                .'granted the app access before — revoke it at '
                .'https://myaccount.google.com/permissions and run this command again.'
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Success. Add these to your .env:');
        $this->newLine();
        $this->line('GOOGLE_DRIVE_AUTH_MODE=oauth');
        $this->line("GOOGLE_DRIVE_OAUTH_CLIENT_ID={$clientId}");
        $this->line("GOOGLE_DRIVE_OAUTH_CLIENT_SECRET={$clientSecret}");
        $this->line("GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN={$token['refresh_token']}");
        $this->newLine();
        $this->comment(
            'The refresh token does not expire on its own — only revoking access or 6 '
            .'months of disuse invalidates it.'
        );

        return self::SUCCESS;
    }

    /**
     * Blocks until a redirect carrying `code` or `error` arrives, reads it off
     * the query string, and tears the listener down.
     *
     * The browser doesn't only send the one request we care about — Chrome in
     * particular fires off a `GET /favicon.ico` on the same origin, and if that
     * lands on our socket before the real redirect does, treating "whatever
     * connects first" as authoritative silently eats the actual authorization.
     * So every connection gets inspected; anything without `code`/`error` gets
     * a quick 404 and we keep listening, budgeted against one overall 120s
     * deadline rather than 120s per attempt.
     */
    private function captureAuthorizationCode(int $port): ?string
    {
        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

        if (! $server) {
            $this->error("Could not listen on 127.0.0.1:{$port} ({$errstr}). Pass --port with a free one.");

            return null;
        }

        $deadline = time() + 120;
        $ignoredRequestLines = [];

        try {
            while (($remaining = $deadline - time()) > 0) {
                $connection = @stream_socket_accept($server, $remaining);

                if (! $connection) {
                    break;
                }

                $requestLine = trim(fgets($connection) ?: '');
                preg_match('#^GET\s+/\??(\S*)\s+HTTP#', $requestLine, $matches);
                parse_str(ltrim($matches[1] ?? '', '?'), $query);

                if (! isset($query['code']) && ! isset($query['error'])) {
                    // Not the redirect we're waiting for (e.g. favicon.ico) —
                    // dismiss it and keep the listener open for the real one.
                    fwrite($connection, "HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n");
                    fclose($connection);
                    $ignoredRequestLines[] = $requestLine !== '' ? $requestLine : '(empty request line)';

                    continue;
                }

                $body = isset($query['code'])
                    ? 'Authorized -- you can close this tab and return to the terminal.'
                    : 'Authorization failed -- you can close this tab and check the terminal.';

                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\n{$body}");
                fclose($connection);

                if (isset($query['error'])) {
                    $this->error("Google returned an error: {$query['error']}");

                    return null;
                }

                return $query['code'];
            }
        } finally {
            fclose($server);
        }

        $this->error('Timed out after 120s waiting for a redirect that carried a code or an error.');

        if ($ignoredRequestLines !== []) {
            $this->line('Requests received on that port that were not it:');
            foreach ($ignoredRequestLines as $line) {
                $this->line("  {$line}");
            }
        } else {
            $this->line('No requests reached this port at all — check the browser actually redirected to '.$this->option('port').', and that nothing else (VPN, firewall, another server) is intercepting it.');
        }

        return null;
    }
}
