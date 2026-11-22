<?php

namespace App\HoyoverseBundle\Model\Pull;

/**
 * Where the automatic pull sync (manual "Sync Pulls" and nightly sync) reads its pages from.
 *
 * - Stoken: a gacha authkey generated from stoken_v2, then getGachaLog (HasStokenPullImportInterface).
 * - Chronicle: the HoYoLAB battle chronicle, with the HoYoLAB web cookies only (HasChroniclePullImportInterface).
 *
 * Chosen by PullSyncSourceResolver from the game and the account cookie.
 */
enum PullSyncSource: string
{
    case Stoken = 'stoken';
    case Chronicle = 'chronicle';
}
