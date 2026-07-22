<?php

namespace Soul\PalworldAdmin\Api;

/**
 * Connection details for a Palworld server's REST API.
 */
final readonly class PalworldConnection
{
    public function __construct(
        public string $host,
        public int $port,
        public string $adminPassword,
    ) {}

    public function baseUrl(): string
    {
        return sprintf('http://%s:%d/v1/api', $this->host, $this->port);
    }
}
