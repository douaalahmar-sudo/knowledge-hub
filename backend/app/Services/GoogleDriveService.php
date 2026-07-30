<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class GoogleDriveService
{
    protected Client $client;
    protected Drive $drive;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->addScope(Drive::DRIVE_FILE);

        match (config('services.google_drive.auth_mode', 'service_account')) {
            'oauth' => $this->authenticateWithOAuth(),
            default => $this->authenticateWithServiceAccount(),
        };

        $this->drive = new Drive($this->client);
    }

    /**
     * Production path. A service account has zero storage quota of its own —
     * every file it creates must land inside a Shared Drive it's a member of,
     * or the API rejects the write with storageQuotaExceeded.
     */
    protected function authenticateWithServiceAccount(): void
    {
        $this->client->setAuthConfig(config('services.google_drive.service_account_path'));
    }

    /**
     * Local/dev fallback for testing uploads under your own Google account
     * before a Shared Drive exists. The refresh token is minted once via
     * `php artisan google-drive:oauth-setup` and never expires on its own —
     * only revoking access or 6 months of disuse invalidates it.
     */
    protected function authenticateWithOAuth(): void
    {
        $clientId = config('services.google_drive.oauth_client_id');
        $clientSecret = config('services.google_drive.oauth_client_secret');
        $refreshToken = config('services.google_drive.oauth_refresh_token');

        if (! $clientId || ! $clientSecret || ! $refreshToken) {
            throw new RuntimeException(
                'GOOGLE_DRIVE_AUTH_MODE=oauth requires GOOGLE_DRIVE_OAUTH_CLIENT_ID, '
                .'GOOGLE_DRIVE_OAUTH_CLIENT_SECRET and GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN in .env. '
                .'Run `php artisan google-drive:oauth-setup` once to obtain the refresh token.'
            );
        }

        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);

        // Exchanges the refresh token for a live access token right away, so
        // every call this instance makes afterwards is already authenticated.
        $this->client->refreshToken($refreshToken);
    }

    public function upload(UploadedFile $file, string $folderId): string
    {
        $driveFile = new DriveFile([
            'name' => $file->getClientOriginalName(),
            'parents' => [$folderId],
        ]);

        $result = $this->drive->files->create($driveFile, [
            'data' => file_get_contents($file->getRealPath()),
            'mimeType' => $file->getMimeType(),
            'uploadType' => 'multipart',
            'fields' => 'id',
        ]);

        return $result->id;
    }

    public function streamFile(string $fileId): string
    {
        $response = $this->drive->files->get($fileId, ['alt' => 'media']);
        return $response->getBody()->getContents();
    }

    public function delete(string $fileId): void
    {
        $this->drive->files->delete($fileId);
    }
}