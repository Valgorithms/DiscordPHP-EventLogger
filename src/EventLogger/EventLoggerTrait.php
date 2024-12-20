<?php declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EventLogger;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\Collection;
use Discord\Parts\Part;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\Ban;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use EmbedBuilder\EmbedBuilder;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

trait EventLoggerTrait
{
    const array REDUNDANT_PREOPRTIES = [
        'edited_timestamp'
    ];

    const GITHUB  = 'https://github.com/valgorithms/discordphp-eventlogger';
    const CREDITS = 'DiscordPHP EventLogger by Valithor Obsidion';
    private readonly string $footer;
    private Discord $discord;
    private bool $setup = false;

    private int $color = 0xE1452D;
    /**
     * @var array<string, string> $log_channel_ids An associative array mapping log channel IDs.
     *                                              Keys represent Guild IDs
     *                                              Values represent the corresponding log channel IDs.
     */
    private array $log_channel_ids = [];
    /**
     * @var array<string, callable> $event_listeners An array of event names to listen for.
     * 
     * @link https://discord.com/developers/docs/events/gateway-events
     */
    private array $event_listeners = [
        'ready' => null,
    ];

    /**
     * This method is called after the constructor.
     * It attempts to retrieve log channels from environment variables.
     *
     * @return void
     */
    public function afterConstruct(Discord &$discord, array $events): void
    {
        if ($this->setup) return;
        if (!isset($this->discord)) $this->discord = $discord;
        $this->footer = self::GITHUB . PHP_EOL . self::CREDITS;
        $this->getLogChannelsFromEnv();
        $this->createDefaultEventListeners($events);
        $this->setup = true;
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
        //if (! $this->discord->guilds->get('id', $guild_id)) throw new \InvalidArgumentException('Guild not found.');
        //if (! $this->discord->getChannel($channel_id)) throw new \InvalidArgumentException('Channel not found.');
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

    public static function getPartDifferences(object $newPart, ?object $oldPart): array
    {
        if (! $oldPart) return [];
        if (!method_exists($newPart, 'getRawAttributes') || !method_exists($oldPart, 'getRawAttributes')) return [];

        $differences = [];

        $newAttributes = $newPart->getRawAttributes();
        $oldAttributes = $oldPart->getRawAttributes();

        foreach ($newAttributes as $key => $newValue) {
            if (array_key_exists($key, $oldAttributes)) {
                $oldValue = $oldAttributes[$key];

                if (is_array($newValue) && is_array($oldValue)) {
                    $addedItems = array_diff($newValue, $oldValue);
                    $removedItems = array_diff($oldValue, $newValue);
                    if (!empty($addedItems) || !empty($removedItems)) $differences[$key] = ['added' => $addedItems, 'removed' => $removedItems];
                } elseif ($newValue instanceof \ArrayAccess && $oldValue instanceof \ArrayAccess) {
                    /** @var Collection $newValue */
                    /** @var Collection $oldValue */
                    $newItems = method_exists($newValue, 'getIterator') ? iterator_to_array($newValue->getIterator()) : iterator_to_array($newValue);
                    $oldItems = method_exists($oldValue, 'getIterator') ? iterator_to_array($oldValue->getIterator()) : iterator_to_array($oldValue);
                    $addedItems = array_diff($newItems, $oldItems);
                    $removedItems = array_diff($oldItems, $newItems);
                    if (!empty($addedItems) || !empty($removedItems)) $differences[$key] = ['added' => $addedItems, 'removed' => $removedItems];
                } elseif (is_object($newValue) && is_object($oldValue)) {
                    $nestedDifferences = self::getPartDifferences($newValue, $oldValue);
                    if (!empty($nestedDifferences)) $differences[$key] = $nestedDifferences;
                } elseif ($newValue !== $oldValue) $differences[$key] = ['new' => $newValue, 'old' => $oldValue];
            } else $differences[$key] = ['new' => $newValue, 'old' => null];
        }

        foreach ($oldAttributes as $key => $oldValue) if (!array_key_exists($key, $newAttributes)) $differences[$key] = ['new' => null, 'old' => $oldValue];

        return self::removeRedundantProperties($differences);
    }

    public static function removeRedundantProperties(array $array): array
    {
        return array_diff_key($array, array_flip(self::REDUNDANT_PREOPRTIES));
    }

    public function getDifferences($newObject, $oldObject): array
    {
        return self::getPartDifferences($newObject, $oldObject);
    }

    private static function arrayRecursiveDiff(array $array1, array $array2): array
    {
        $difference = [];
        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!isset($array2[$key]) || !is_array($array2[$key])) {
                    $difference[$key] = $value;
                } else {
                    $new_diff = self::arrayRecursiveDiff($value, $array2[$key]);
                    if (!empty($new_diff)) {
                        $difference[$key] = $new_diff;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                $difference[$key] = $value;
            }
        }
        return $difference;
    }

    /**
     * Logs an event to a specified Discord channel.
     *
     * @param string $event The name of the event to log.
     * @param string $guild_id The ID of the guild where the event occurred.
     * @param Part|object|string $content The content of the event to log. Can be an object or a string.
     * @param Part|object|string|null $old_content The previous content of the event, used to determine changes. Can be an object or a string. Default is null.
     * 
     * @return PromiseInterface A promise that resolves when the event has been logged.
     * 
     * @throws \Exception If the Discord Channel ID is not configured, the Discord Guild is not found, or the Discord Channel is not found.
     * 
     * @uses MessageBuilder to create a message with the event content.
     * @uses EmbedBuilder to create an embed with the event content.
     * 
     * @example To override this function to log the event using Monolog instead of sending a message to a Discord channel, you can extend the class and override the logEvent method:
     * 
     * <code>
     * use Monolog\Logger;
     * use Monolog\Handler\StreamHandler;
     * 
     * class CustomEventLogger {
     *     use EventLoggerTrait;
     * 
     *     protected $logger;
     * 
     *     public function __construct() {
     *         $this->logger = new Logger('event_logger');
     *         $this->logger->pushHandler(new StreamHandler(__DIR__.'/events.log', Logger::INFO));
     *     }
     * 
     *     public function logEvent(
     *         Discord $discord,
     *         string $event,
     *         string $guild_id,
     *         object|string $content,
     *         object|string $old_content = null
     *     ): PromiseInterface {
     *         $description = is_object($content) ? json_encode($content) : $content;
     *         $this->logger->info("Event: $event, Guild ID: $guild_id, Content: $description");
     *         return resolve();
     *     }
     * }
     * </code>
     */
    public function logEvent(
        Discord $discord,
        string $event,
        string $guild_id,
        object|string $content,
        object $old_content = null
    ): PromiseInterface
    {
        $differences = $this->getDifferences($content, $old_content);
        $discord->getLogger()->info("Logging event: $event, Guild ID: {$guild_id}, Differences: " . json_encode($differences), [
            'event' => $event,
            'guild_id' => $guild_id,
            'differences' => $differences
        ]);

        if (! $channel_id = $this->log_channel_ids[$guild_id] ?? null) return reject(new \Exception('Discord Channel ID not configured'));
        if (! $guild = $discord->guilds->get('id', $guild_id)) return reject(new \Exception('Discord Guild not found'));
        if (! $channel = $guild->channels->get('id', $channel_id)) return reject(new \Exception('Discord Channel not found'));

        if (is_string($content)) return $channel->sendMessage(MessageBuilder::new()->setContent($content));

        $description = '';
        if (!empty($differences)) {
            foreach ($differences as $key => $diff) {
                if (is_array($diff)) {
                    if (isset($diff['added']) && !empty($diff['added'])) $description .= "$key added: " . json_encode($diff['added']) . PHP_EOL;
                    if (isset($diff['removed']) && !empty($diff['removed'])) $description .= "$key removed: " . json_encode($diff['removed']) . PHP_EOL;
                    if (isset($diff['new']) && isset($diff['old'])) {
                        $description .= "$key changed:" . PHP_EOL;
                        $description .= "Old: `{$diff['old']}`" . PHP_EOL;
                        $description .= "New: `{$diff['new']}`" . PHP_EOL;
                    }
                } else $description .= "$key: " . json_encode($diff) . PHP_EOL;
            }
        } else $description = is_object($content) ? json_encode($content) : (is_array($content) ? json_encode($content) : $content);

        if (! $description) return reject(new \Exception('No content to log'));
        if (strlen($description) <= 4096) return $channel->sendMessage(MessageBuilder::new()->addEmbed(EmbedBuilder::new($discord, $this->color, $this->footer)->setDescription($description)->setTitle($event)));
        return $channel->sendMessage(MessageBuilder::new()->addFileFromContent("$event.txt", $description));
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

    private function createDefaultEventListeners(
        array $events
    ): void
    {
        $callableEvents = array_filter($events, 'is_callable');
        $this->event_listeners = array_merge($this->event_listeners, $callableEvents);
        $eventKeys = array_flip(array_values($events));

        if (!isset($this->event_listeners['CHANNEL_CREATE']) && isset($eventKeys['CHANNEL_CREATE'])) {
            $this->event_listeners['CHANNEL_CREATE'] = fn(Channel $channel, Discord $discord) => $this->logEvent(
                $discord,
                'CHANNEL_CREATE',
                $channel->guild_id,
                "Channel created: {$channel->name}"
            );
        }

        if (!isset($this->event_listeners['CHANNEL_DELETE']) && isset($eventKeys['CHANNEL_DELETE'])) {
            $this->event_listeners['CHANNEL_DELETE'] = fn(Channel $channel, Discord $discord) => $this->logEvent(
                $discord,
                'CHANNEL_DELETE',
                $channel->guild_id,
                "Channel deleted: {$channel->name}"
            );
        }

        if (!isset($this->event_listeners['CHANNEL_UPDATE']) && isset($eventKeys['CHANNEL_UPDATE'])) {
            $this->event_listeners['CHANNEL_UPDATE'] = fn(Channel $newChannel, Discord $discord, ?Channel $oldChannel) => $this->logEvent(
                $discord,
                'CHANNEL_UPDATE',
                $newChannel->guild_id,
                $newChannel,
                $oldChannel
            );
        }

        if (!isset($this->event_listeners['GUILD_BAN_ADD']) && isset($eventKeys['GUILD_BAN_ADD'])) {
            $this->event_listeners['GUILD_BAN_ADD'] = fn(Ban $ban, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_BAN_ADD',
                $ban->guild_id,
                "User banned: {$ban->user}"
            );
        }

        if (!isset($this->event_listeners['GUILD_BAN_REMOVE']) && isset($eventKeys['GUILD_BAN_REMOVE'])) {
            $this->event_listeners['GUILD_BAN_REMOVE'] = fn(Ban $ban, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_BAN_REMOVE',
                $ban->guild_id,
                "User unbanned: {$ban->user}"
            );
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_ADD']) && isset($eventKeys['GUILD_MEMBER_ADD'])) {
            $this->event_listeners['GUILD_MEMBER_ADD'] = fn(Member $member, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_MEMBER_ADD',
                $member->guild_id,
                "Member joined: {$member->user}"
            );
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_REMOVE']) && isset($eventKeys['GUILD_MEMBER_REMOVE'])) {
            $this->event_listeners['GUILD_MEMBER_REMOVE'] = fn(Member $member, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_MEMBER_REMOVE',
                $member->guild_id,
                "Member left: {$member->user}"
            );
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_UPDATE']) && isset($eventKeys['GUILD_MEMBER_UPDATE'])) {
            $this->event_listeners['GUILD_MEMBER_UPDATE'] = fn(Member $newMember, Discord $discord, ?Member $oldMember) => $this->logEvent(
                $discord,
                'GUILD_MEMBER_UPDATE',
                $newMember->guild_id,
                $newMember,
                $oldMember
            );
        }

        if (!isset($this->event_listeners['GUILD_ROLE_CREATE']) && isset($eventKeys['GUILD_ROLE_CREATE'])) {
            $this->event_listeners['GUILD_ROLE_CREATE'] = fn(Role $role, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_ROLE_CREATE',
                $role->guild_id,
                "Role created: {$role->name}" . PHP_EOL . "with permissions: " . implode(', ', $role->permissions->getPermissions())
            );
        }

        if (!isset($this->event_listeners['GUILD_ROLE_DELETE']) && isset($eventKeys['GUILD_ROLE_DELETE'])) {
            $this->event_listeners['GUILD_ROLE_DELETE'] = fn(Role $role, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_ROLE_DELETE',
                $role->guild_id,
                "Role deleted: `" . ($role->name ?? '[Name not cached]') . "`" . PHP_EOL . "ID: {$role->id}"
            );
        }

        if (!isset($this->event_listeners['GUILD_ROLE_UPDATE']) && isset($eventKeys['GUILD_ROLE_UPDATE'])) {
            $this->event_listeners['GUILD_ROLE_UPDATE'] = fn(Role $newRole, Discord $discord, Role $oldRole) => $this->logEvent(
                $discord,
                'GUILD_ROLE_UPDATE',
                $newRole->guild_id,
                $newRole,
                $oldRole
            );
        }

        if (!isset($this->event_listeners['MESSAGE_UPDATE']) && isset($eventKeys['MESSAGE_UPDATE'])) {
            $this->event_listeners['MESSAGE_UPDATE'] = fn(Message $message, Discord $discord, ?Message $oldMessage) => $this->logEvent(
                $discord,
                'MESSAGE_UPDATE',
                $message->guild_id,
                $message,
                $oldMessage
            );
        }
        
        if (!isset($this->event_listeners['MESSAGE_DELETE']) && isset($eventKeys['MESSAGE_DELETE'])) {
            /** @param Message|object $message */
            $this->event_listeners['MESSAGE_DELETE'] = fn(object $message, Discord $discord) => $this->logEvent(
                $discord,
                'MESSAGE_DELETE',
                $message->guild_id,
                "Message deleted (ID: {$message->id}) by {$message->author->username}: {$message->content}" . 
                    (!empty($message->attachments) ? PHP_EOL . "Attachments: " . implode(', ', array_map(fn($attachment) => $attachment->url, $message->attachments->toArray())) : '') . 
                    ($message->referenced_message ? PHP_EOL . "Replied to: {$message->referenced_message->content}" : '')
            );
        }

        if (!isset($this->event_listeners['USER_UPDATE']) && isset($eventKeys['USER_UPDATE'])) {
            $this->event_listeners['USER_UPDATE'] = function (User $newUser, Discord $discord, ?User $oldUser) {
                if ($newUser->id == $discord->id) return; // Ignore user updates by this bot
                foreach ($discord->guilds as $guild) if ($guild->members->get('id', $newUser->id)) $this->logEvent(
                    $discord,
                    'USER_UPDATE',
                    $guild->id,
                    $newUser,
                    $oldUser
                );
            };
        }

        // Add more event listeners as needed

        $this->createEventListeners($events);
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
        foreach ($this->event_listeners as $event => $listener) if (is_callable($listener)) $this->discord->on($event, $listener);
    }
}