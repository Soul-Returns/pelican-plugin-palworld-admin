<?php

namespace Soul\PalworldAdmin\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the official Palworld dedicated server REST API.
 *
 * https://docs.palworldgame.com/category/rest-api/
 * All endpoints use HTTP Basic auth with username "admin" and the server's AdminPassword.
 */
class PalworldClient
{
    public function __construct(
        private readonly PalworldConnection $connection,
        private readonly int $timeout = 5,
    ) {}

    public function info(): array
    {
        return $this->get('/info');
    }

    /** @return Player[] */
    public function players(): array
    {
        $data = $this->get('/players');

        return array_map(Player::fromArray(...), $data['players'] ?? []);
    }

    public function settings(): array
    {
        return $this->get('/settings');
    }

    public function metrics(): array
    {
        return $this->get('/metrics');
    }

    public function announce(string $message): void
    {
        $this->post('/announce', ['message' => $message]);
    }

    public function kick(string $userId, string $message = 'You have been kicked.'): void
    {
        $this->post('/kick', ['userid' => $userId, 'message' => $message]);
    }

    public function ban(string $userId, string $message = 'You have been banned.'): void
    {
        $this->post('/ban', ['userid' => $userId, 'message' => $message]);
    }

    public function unban(string $userId): void
    {
        $this->post('/unban', ['userid' => $userId]);
    }

    public function save(): void
    {
        $this->post('/save');
    }

    public function shutdown(int $waitSeconds = 30, string $message = 'Server is shutting down.'): void
    {
        $this->post('/shutdown', ['waittime' => $waitSeconds, 'message' => $message]);
    }

    public function forceStop(): void
    {
        $this->post('/stop');
    }

    private function get(string $endpoint): array
    {
        return $this->send('GET', $endpoint)->json() ?? [];
    }

    private function post(string $endpoint, array $body = []): void
    {
        $this->send('POST', $endpoint, $body);
    }

    private function send(string $method, string $endpoint, array $body = []): Response
    {
        try {
            $response = $this->request()->send($method, $this->connection->baseUrl() . $endpoint, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw PalworldApiException::unreachable($this->connection->host, $this->connection->port, $e->getMessage());
        }

        if ($response->failed()) {
            throw PalworldApiException::badResponse($response->status(), $endpoint);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth('admin', $this->connection->adminPassword)
            ->timeout($this->timeout)
            ->connectTimeout(3)
            ->acceptJson();
    }
}
