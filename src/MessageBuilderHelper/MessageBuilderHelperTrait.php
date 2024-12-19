<?php declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace MessageBuilderHelper;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Embed\Embed;

trait MessageBuilderHelperTrait
{
    /**
     * Creates a new MessageBuilder instance and adds the provided embeds to it.
     *
     * @param Embed|array<Embed> ...$embeds The embeds to add to the MessageBuilder. Can be instances of Embed or arrays.
     * @return MessageBuilder The MessageBuilder instance with the added embeds.
     */
    private static function new(
        Discord $discord,
        Embed|array ...$embeds
    ): MessageBuilder
    {
        return (new MessageBuilder($discord))
            ->addEmbed($embeds);
    }
}