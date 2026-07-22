<?php

namespace Soul\PalworldAdmin\Api;

final readonly class Player
{
    public function __construct(
        public string $name,
        public string $accountName,
        public string $playerId,
        public string $userId,
        public string $ip,
        public float $ping,
        public float $locationX,
        public float $locationY,
        public int $level,
        public int $buildingCount,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            accountName: (string) ($data['accountName'] ?? ''),
            playerId: (string) ($data['playerId'] ?? ''),
            userId: (string) ($data['userId'] ?? ''),
            ip: (string) ($data['ip'] ?? ''),
            ping: (float) ($data['ping'] ?? 0),
            locationX: (float) ($data['location_x'] ?? 0),
            locationY: (float) ($data['location_y'] ?? 0),
            level: (int) ($data['level'] ?? 0),
            buildingCount: (int) ($data['building_count'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'accountName' => $this->accountName,
            'playerId' => $this->playerId,
            'userId' => $this->userId,
            'ip' => $this->ip,
            'ping' => $this->ping,
            'location_x' => $this->locationX,
            'location_y' => $this->locationY,
            'level' => $this->level,
            'building_count' => $this->buildingCount,
        ];
    }
}
