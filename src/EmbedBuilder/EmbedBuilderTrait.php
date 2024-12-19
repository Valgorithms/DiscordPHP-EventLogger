<?php declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EmbedBuilder;

use Discord\Discord;
use Discord\Parts\Embed\Embed;

trait EmbedBuilderTrait
{
    /**
     * Creates an Embed object with the specified footer and color.
     *
     * @param bool|null $footer Whether to include the footer in the embed. Defaults to true.
     * @param int|null $color The color of the embed. Defaults to null.
     * 
     * @return Embed The created Embed object.
     */
    private static function createEmbed(
        Discord $discord,
        string|int|null $color = null,
        string|null $footer = null
    ): Embed
    {
        return (new Embed($discord))
            ->setFooter($footer ?: '')
            ->setColor($color ?: null)
            ->setTimestamp()
            ->setURL('');
    }

    /**
     * Fills an Embed object with the provided fields.
     *
     * @param string|null $title The title of the embed (Event).
     * @param string|null $description The description of the embed.
     * @param Embed|null $embed The Embed object to fill. If null, a new Embed object will be created.
     * @param Discord|null $discord The Discord instance. If null, an Embed instance must be provided.
     * @param string|int|null $color The color of the embed. Can be a hex string or an integer. If null, a default color will be used.
     * @param string|null $embed_footer The footer text of the embed. If null, no footer will be added.
     * @param array $fields Array containing associative arrays of fields to add to the Embed. Each field should have 'name', 'value', and optionally 'inline' keys.
     * @return Embed The filled Embed object.
     * @throws \Exception If both Discord and Embed instances are null.
     */
    public static function new(
        Embed|null $embed = null,
        string|null $title = null, // Event
        string|null $description = null,
        array $fields = [],
        Discord|null $discord = null,
        string|int|null $color = null,
        string|null $footer = null
    ): Embed
    {
        if ($discord === null && $embed === null) throw new \Exception('Either Discord or Embed instance must be provided');
        
        $embed = $embed ?? self::createEmbed($discord, $color, $footer);

        if ($title) $embed->setTitle($title);
        if ($description) $embed->setDescription($description);

        array_walk($fields, fn($field) => isset($field['name'], $field['value']) ? $embed->addFieldValues($field['name'], $field['value'], $field['inline'] ?? false) : null);

        return $embed;
    }
}