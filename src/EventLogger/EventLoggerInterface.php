<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EventLogger;

use Discord\Builders\MessageBuilder;
use React\Promise\PromiseInterface;

interface EventLoggerInterface
{
    /**
     * Route a guild's audit events to a log channel.
     *
     * @throws \InvalidArgumentException when either id is not numeric
     */
    public function addLogChannel(string $guild_id, string $channel_id): void;

    /** Stop logging a guild (removes its channel mapping). */
    public function removeLogGuild(string $guild_id): void;

    /**
     * Send one event to its guild's log channel. Rejects when the guild has no
     * channel configured, or the guild / channel cannot be resolved.
     *
     * @return PromiseInterface<\Discord\Parts\Channel\Message>
     */
    public function logEvent(
        \Discord\Discord $discord,
        string $event,
        string $guild_id,
        object|string $content,
        ?object $old_content = null,
        ?MessageBuilder $builder = null
    ): PromiseInterface;
}
