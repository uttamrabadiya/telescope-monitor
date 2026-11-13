<?php

namespace TelescopeMonitor\Integrations;

use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Auth\Capabilities;

final class GitHubRepositories
{
    public function __construct(private Capabilities $caps, private ProviderRouter $provider, private DestinationPolicy $policy) {}

    public function discover(array $values): array
    {
        abort_unless($this->caps->allows('manage'), 403);
        $token = $values['credential'] ?? '';
        if (! empty($values['credential_reference'])) {
            $record = config('telescope-monitor.integrations.credentials', [])[$values['credential_reference']] ?? null;
            if (! is_array($record) || ($record['purpose'] ?? null) !== 'github_token') {
                throw ValidationException::withMessages(['credential_reference' => 'Select an approved GitHub credential reference.']);
            }
            $token = $record['value'] ?? '';
        }
        if (! is_string($token) || $token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw ValidationException::withMessages(['credential' => 'Enter a valid personal access token without whitespace.']);
        }
        $page = (int) ($values['page'] ?? 1);
        $response = $this->provider->request('GET', 'https://api.github.com/user/repos?per_page=30&sort=full_name&page='.$page, 'github', [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2026-03-10',
        ], '', 1048576);
        if ($response->status !== 200) {
            $message = match ($response->status) {
                0 => match ($response->failure) {
                    'response_too_large' => 'GitHub returned a repository list larger than the supported response limit. Enter the repository manually.',
                    'timeout' => 'The connection to GitHub timed out. Try again and check the application server’s outbound network access.',
                    'tls' => 'The application server could not verify GitHub’s TLS certificate. Check the server’s CA certificate configuration.',
                    default => 'The application server could not connect to GitHub. Check outbound HTTPS access, DNS and the PHP cURL extension, then try again.',
                },
                422 => 'GitHub rejected the repository query. Try again or enter the repository manually.',
                401 => 'GitHub rejected this token. Check that it is correct and has not expired.',
                403, 429 => 'GitHub denied or rate-limited repository access. Check token permissions and organization approval, then try again.',
                default => 'Could not load GitHub repositories. Try again or enter the repository manually.',
            };
            throw ValidationException::withMessages(['credential' => $message]);
        }
        $repositories = [];
        $rows = $response->json();
        if (! array_is_list($rows)) {
            throw ValidationException::withMessages(['credential' => 'GitHub returned an invalid repository list. Try again.']);
        }
        foreach (array_slice($rows, 0, 30) as $row) {
            if (! is_array($row) || ($row['archived'] ?? true) || ($row['disabled'] ?? true) || ! ($row['has_issues'] ?? false)) {
                continue;
            }
            $owner = $row['owner']['login'] ?? null;
            $name = $row['name'] ?? null;
            if (! is_string($owner) || ! is_string($name)) {
                continue;
            }
            try {
                $repo = $this->policy->repository($owner, $name);
            } catch (ValidationException) {
                continue;
            }
            $repositories[] = $repo + ['full_name' => $owner.'/'.$name, 'private' => (bool) ($row['private'] ?? false)];
        }

        // Never follow provider-supplied pagination URLs with credentials.
        return ['repositories' => $repositories, 'next_page' => count($rows) === 30 && $page < 100 ? $page + 1 : null];
    }
}
