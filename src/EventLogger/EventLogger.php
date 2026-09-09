<?php

declare(strict_types=1);

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

    /** Gateway events wired up by default when no explicit list is passed. */
    public const array DEFAULT_EVENTS = [
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
        'MESSAGE_UPDATE',
    ];

    /**
     * @param Discord            $discord
     * @param list<string>|null  $events Gateway event names to log; null uses {@see self::DEFAULT_EVENTS}.
     *                                   A `'EVENT' => callable` entry overrides that event's default handler.
     */
    public function __construct(
        private Discord $discord,
        private ?array $events = null,
    ) {
        $this->events ??= self::DEFAULT_EVENTS;
        $this->afterConstruct($discord, $this->events);
    }
}
