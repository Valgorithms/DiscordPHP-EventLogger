<?php declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EventLogger;

use EmbedBuilder\EmbedBuilderTrait;

class EventLogger implements EventLoggerInterface
{
    use EventLoggerTrait;
    use EmbedBuilderTrait;
}