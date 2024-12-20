<?php declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EmbedBuilder;

use Discord\Discord;
use Discord\Parts\Embed\Embed;

interface EmbedBuilderInterface
{
    public static function new(
        Discord $discord,
        string|int|null $color = null,
        string|null $footer = null
    ): Embed;
    public static function fill(
        Embed|null $embed = null,
        string|null $title = null, // Event
        string|null $description = null,
        array $fields = [],
        Discord|null $discord = null,
        string|int|null $color = null,
        string|null $footer = null
    ): Embed;
}