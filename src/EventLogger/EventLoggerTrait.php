<?php declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Embed\Embed;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

trait EventLoggerTrait
{
    const GITHUB  = 'https://github.com/vzgcoders/discordphp-eventlogger';
    const CREDITS = 'DiscordPHP EventLogger by Valithor Obsidion';
    private readonly string $embed_footer;

    private int $color = 0xE1452D;
    /**
     * @var array<string, string> $log_channel_ids An associative array mapping log channel IDs.
     *                                              Keys represent Guild IDs
     *                                              Values represent the corresponding log channel IDs.
     */
    private array $log_channel_ids = [];
    /**
     * @var array<string, string> $event_listeners An array of event names to listen for.
     * 
     * @link https://discord.com/developers/docs/events/gateway-events
     */
    private array $event_listeners = [
        'ready' => null,
    ];

    public function __construct(
        private Discord $discord,
        private array $events = []
    )
    {
        $this->embed_footer = self::GITHUB . PHP_EOL . self::CREDITS;
        $this->afterConstruct($events);
    }

    /**
     * This method is called after the constructor.
     * It attempts to retrieve log channels from environment variables.
     *
     * @return void
     */
    private function afterConstruct(array $events): void
    {
        $this->getLogChannelsFromEnv();
        $this->createDefaultEventListeners($events);
        $this->createEventListeners($events);
    }

    /**
     * @param string $guild_id   The ID of the guild.
     * @param string $channel_id The ID of the log channel.
     * @return void
     * @throws \InvalidArgumentException If the guild ID or channel ID is not numeric,
     *                                      if the guild is not found,
     *                                      or if the channel is not found.
     */
    public function addLogChannel(
        string $guild_id,
        string $channel_id
    ): void
    {
        if (! is_numeric($guild_id) || ! is_numeric($channel_id)) throw new \InvalidArgumentException('Guild ID and Channel ID must be numeric.');
        if (! $this->discord->guilds->get('id', $guild_id)) throw new \InvalidArgumentException('Guild not found.');
        if (! $this->discord->getChannel($channel_id)) throw new \InvalidArgumentException('Channel not found.');
        $this->log_channel_ids[$guild_id] = $channel_id;
    }

    /**
     * Removes the log channel IDs associated with the specified guild ID.
     *
     * @param string $guild_id The ID of the guild whose log channel ID should be removed.
     * @return void
     */
    public function removeLogGuild(
        string $guild_id
    ): void
    {
        unset($this->log_channel_ids[$guild_id]);
    }

    /**
     * Logs an event to a specified Discord channel.
     *
     * @param string $guild_id The ID of the guild where the event occurred.
     * @param MessageBuilder $builder The message builder containing the event details.
     * @return PromiseInterface A promise that resolves when the message is sent.
     * @throws \Exception If the Discord channel is not found.
     */
    public function logEvent(
        string $guild_id,
        MessageBuilder $builder
    ): PromiseInterface
    {
        if (! $channel_id = $this->log_channel_ids[$guild_id] ?? null) return reject(new \Exception('Discord Channel ID not configured'));
        if (! $channel = $this->discord->guilds->channels->get('id', $channel_id)) return reject(new \Exception('Discord Channel not found'));
        /** @var \Discord\Parts\Channel\Channel $channel */
        return $channel->sendMessage($builder);
    }

    /*
     * Attempted to initialize the log Guild and Channel IDs after the object construction.
     *
     * This method attempts to retrieve a list of guilds and their respective log channels
     * from the environment variable `GUILD_CHANNELS`. The `GUILD_CHANNELS` variable is expected
     * to be a comma-separated string where each entry is a guild-channel pair separated by a hyphen.
     * 
     * Example of `DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS` value: "1077144430588469349-1077144432463314998,1253459964849164328-1253480680583860367"
     *
     */
    private function getLogChannelsFromEnv(): void
    {
        array_map(fn($pair) => $this->addLogChannel(...explode('-', $pair)), explode(',', getenv('DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS')));
    }

    private function createDefaultEventListeners(array $events):void
    {
        // TODO: Implement default event listeners
    }
    
    /**
     * Registers event listeners with the Discord client.
     *
     * This method iterates through the `$event_listeners` array and registers each listener
     * with the Discord client if it is callable. The listener is associated with the corresponding event.
     *
     * @return void
     */
    private function createEventListeners(): void
    {
        array_walk($this->event_listeners, fn($listener, $event) => is_callable($listener) && $this->discord->on($event, $listener));
    }

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
        string $embed_footer = '',
        string|int|null $color = null,
        bool $include_footer = true
    ): Embed
    {
        return (new Embed($discord))
            ->setFooter(
                $include_footer
                    ? ($embed_footer ?? '')
                    : ''
            )
            ->setColor($color ?? 0xE1452D)
            ->setTimestamp()
            ->setURL('');
    }

    /**
     * Populates an Embed object with the given title and description.
     *
     * @param string $title The title of the embed. Default is an empty string.
     * @param string $description The description of the embed. Default is an empty string.
     * @param Embed|null $embed The Embed object to populate. If null, a new Embed object will be created.
     * @return Embed The populated Embed object.
     */
    private static function populateEmbed(
        Discord $discord,
        string $embed_footer = '',
        string|int|null $color = null,
        bool $include_footer = true,
        string $title = '', // Event
        string $description = '',
        ?Embed $embed
    ): Embed
    {
        return ($embed ?? self::createEmbed($discord, $embed_footer, $color, $include_footer))
            ->setTitle($title)
            ->setDescription($description);
    }

    /**
     * Fills an Embed object with the provided fields.
     *
     * @param Embed|null $embed The Embed object to fill. If null, a new Embed object will be created.
     * @param array ...$fields Associative arrays of fields to add to the Embed. Each field should have 'name', 'value', and optionally 'inline' keys.
     * 
     * @return Embed The filled Embed object.
     */
    private static function fillEmbed(
        Discord $discord,
        string $embed_footer = '',
        string|int|null $color = null,
        bool $include_footer = true,
        ?Embed $embed,
        array ...$fields, // Assoc array of fields
    ): Embed
    {
        $embed = ($embed ?? self::createEmbed($discord, $embed_footer, $color, $include_footer));
        array_map(fn($field) => $embed->addFieldValues($field['name'], $field['value'], $field['inline'] ?? false), $fields);
        return $embed;
    }

    /**
     * Creates a new MessageBuilder instance and adds the provided embeds to it.
     *
     * @param Embed|array<Embed> ...$embeds The embeds to add to the MessageBuilder. Can be instances of Embed or arrays.
     * @return MessageBuilder The MessageBuilder instance with the added embeds.
     */
    private static function createBuilder(
        Discord $discord,
        Embed|array ...$embeds
    ): MessageBuilder
    {
        return (new MessageBuilder($discord))
            ->addEmbed($embeds);
    }
}