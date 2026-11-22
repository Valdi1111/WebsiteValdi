<?php

namespace App\GamesBundle\Model\Pull;

enum PullOperationKind: string
{
    case BACKUP_IMPORT = 'backup_import';
    case POWERSHELL_IMPORT = 'powershell_import';
    case STOKEN_SYNC = 'stoken_sync';
    case AUTO_SYNC = 'auto_sync';
    case SCHEDULED_SYNC = 'scheduled_sync';
    case S_RANK_HISTORY_IMPORT = 's_rank_history_import';

    public function getLabel(): string
    {
        return match ($this) {
            self::BACKUP_IMPORT => 'Backup Import',
            self::POWERSHELL_IMPORT => 'PowerShell Import',
            self::STOKEN_SYNC, self::AUTO_SYNC => 'Sync Pulls',
            self::SCHEDULED_SYNC => 'Automatic Sync',
            self::S_RANK_HISTORY_IMPORT => 'Official S-Rank History',
        };
    }
}
