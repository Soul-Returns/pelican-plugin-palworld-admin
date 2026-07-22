<?php

namespace Soul\PalworldAdmin\Api;

use Exception;

class PalworldApiException extends Exception
{
    public static function unreachable(string $host, int $port, string $reason): self
    {
        return new self("Palworld REST API at {$host}:{$port} is unreachable: {$reason}");
    }

    public static function badResponse(int $status, string $endpoint): self
    {
        return new self("Palworld REST API returned HTTP {$status} for {$endpoint}" . ($status === 401 ? ' (check the admin password)' : ''));
    }
}
