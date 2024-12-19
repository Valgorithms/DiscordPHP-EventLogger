<?php declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EventLogger;

use Discord\Discord;
use EmbedBuilder\EmbedBuilderTrait;

class EventLogger implements EventLoggerInterface
{
    use EventLoggerTrait;
    use EmbedBuilderTrait;

    public function __construct(
        private Discord &$discord,
        private array $events = [
            'CHANNEL_CREATE',
            'CHANNEL_DELETE',
            'CHANNEL_UPDATE',
            'GUILD_BAN_ADD',
            'GUILD_BAN_REMOVE',
            'GUILD_MEMBER_ADD',
            'GUILD_MEMBER_REMOVE',
            'GUILD_MEMBER_UPDATE',
            'GUILD_ROLE_CREATE',
            'GUILD_ROLE_DELETE',
            'GUILD_ROLE_UPDATE',
            'MESSAGE_DELETE',
        ]
    )
    {
        $this->afterConstruct($discord, $events);
    }
}