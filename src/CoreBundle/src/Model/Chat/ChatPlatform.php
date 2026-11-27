<?php

namespace App\CoreBundle\Model\Chat;

/**
 * Where a chat request comes from and where its answer goes. Cli is chat:run, for development and tests.
 */
enum ChatPlatform: string
{
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Cli = 'cli';
}
