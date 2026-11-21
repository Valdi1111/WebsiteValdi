<?php

namespace App\PerfectworldBundle\Model\Sdk;

/**
 * Game role of a Perfect World account, from /game/getRoleList/bi.do (roleMap).
 */
final readonly class PwRole
{
    public function __construct(
        public string $roleId,
        public ?string $roleName,
        public ?string $serverId,
        public ?string $serverName,
        public ?int $level,
        public ?string $avatar,
        public ?string $firstLogin,
        public ?string $lastLogin,
    ) {
    }

    /**
     * @param array<string, mixed> $roleMap
     */
    public static function fromRoleMap(array $roleMap): ?self
    {
        $roleId = trim((string) ($roleMap['roleId'] ?? ''));
        if ($roleId === '') {
            return null;
        }

        $string = static fn (string $key): ?string => isset($roleMap[$key]) && trim((string) $roleMap[$key]) !== ''
            ? trim((string) $roleMap[$key])
            : null;

        return new self(
            roleId: $roleId,
            roleName: $string('roleName'),
            serverId: $string('serverId'),
            serverName: $string('serverName'),
            level: isset($roleMap['lev']) && is_numeric($roleMap['lev']) ? (int) $roleMap['lev'] : null,
            avatar: $string('avatar'),
            firstLogin: $string('firstLogin'),
            lastLogin: $string('lastLogin'),
        );
    }
}
