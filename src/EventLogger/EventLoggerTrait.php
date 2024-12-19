<?php declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EventLogger;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\Ban;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Member;
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
     * @var array<string, callable> $event_listeners An array of event names to listen for.
     * 
     * @link https://discord.com/developers/docs/events/gateway-events
     */
    private array $event_listeners = [
        'ready' => null,
    ];

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
        if (! $guild = $this->discord->guilds->get('id', $guild_id)) return reject(new \Exception('Discord Guild not found'));
        if (! $channel = $guild->channels->get('id', $channel_id)) return reject(new \Exception('Discord Channel not found'));
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

    private function createDefaultEventListeners(
        array $events
    ): void
    {
        $callableEvents = array_filter($events, 'is_callable');
        $this->event_listeners = array_merge($this->event_listeners, $callableEvents);
        $eventKeys = array_flip(array_values($events));

        if (!isset($this->event_listeners['CHANNEL_CREATE']) && isset($eventKeys['CHANNEL_CREATE'])) {
            $this->event_listeners['CHANNEL_CREATE'] = function (Channel $channel) {
                $builder = MessageBuilder::new()
                    ->setContent("Channel created: {$channel->name}");
                $this->logEvent($channel->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['CHANNEL_DELETE']) && isset($eventKeys['CHANNEL_DELETE'])) {
            $this->event_listeners['CHANNEL_DELETE'] = function (Channel $channel) {
                $builder = MessageBuilder::new()
                    ->setContent("Channel deleted: {$channel->name}");
                $this->logEvent($channel->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['CHANNEL_UPDATE']) && isset($eventKeys['CHANNEL_UPDATE'])) {
            $this->event_listeners['CHANNEL_UPDATE'] = function (Channel $newChannel, ?Channel $oldChannel) {
                $builder = MessageBuilder::new()
                    ->setContent("Channel updated from: {$oldChannel->name}" . PHP_EOL . "to: {$newChannel->name}");
                $this->logEvent($newChannel->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['GUILD_BAN_ADD']) && isset($eventKeys['GUILD_BAN_ADD'])) {
            $this->event_listeners['GUILD_BAN_ADD'] = function (Ban $ban) {
                $builder = MessageBuilder::new()
                    ->setContent("User banned: {$ban->user->username}");
                $this->logEvent($ban->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['GUILD_BAN_REMOVE']) && isset($eventKeys['GUILD_BAN_REMOVE'])) {
            $this->event_listeners['GUILD_BAN_REMOVE'] = function (Ban $ban) {
                $builder = MessageBuilder::new()
                    ->setContent("User unbanned: {$ban->user->username}");
                $this->logEvent($ban->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_ADD']) && isset($eventKeys['GUILD_MEMBER_ADD'])) {
            $this->event_listeners['GUILD_MEMBER_ADD'] = function (Member $member) {
                $builder = MessageBuilder::new()
                    ->setContent("Member joined: {$member->user->username}");
                $this->logEvent($member->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_REMOVE']) && isset($eventKeys['GUILD_MEMBER_REMOVE'])) {
            $this->event_listeners['GUILD_MEMBER_REMOVE'] = function (Member $member) {
                $builder = MessageBuilder::new()
                    ->setContent("Member left: {$member->user->username}");
                $this->logEvent($member->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_UPDATE']) && isset($eventKeys['GUILD_MEMBER_UPDATE'])) {
            $this->event_listeners['GUILD_MEMBER_UPDATE'] = function (Member $newMember, ?Member $oldMember) {
                if ($oldMember->nick !== $newMember->nick) {
                    $builder = MessageBuilder::new()
                        ->setContent("Nickname changed from: {$oldMember->nick}" . PHP_EOL . "to: {$newMember->nick}");
                    $this->logEvent($newMember->guild_id, $builder);
                }

                if ($oldMember->roles !== $newMember->roles) {
                    $builder = MessageBuilder::new()
                        ->setContent("Roles updated for: {$newMember->user->username}");
                    $this->logEvent($newMember->guild_id, $builder);
                }
            };
        }

        if (!isset($this->event_listeners['GUILD_ROLE_CREATE']) && isset($eventKeys['GUILD_ROLE_CREATE'])) {
            $this->event_listeners['GUILD_ROLE_CREATE'] = function (Role $role) {
                $permissionsList = implode(', ', $role->permissions->getPermissions());
                $builder = MessageBuilder::new()
                    ->setContent("Role created: {$role->name}" . PHP_EOL . "with permissions: {$permissionsList}");
                $this->logEvent($role->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['GUILD_ROLE_DELETE']) && isset($eventKeys['GUILD_ROLE_DELETE'])) {
            $this->event_listeners['GUILD_ROLE_DELETE'] = function ($role) {
                /** @var Role|Object $role Only the guild_id and role_id are guaranteed */
                $roleName = $role->name ?? '[Name not cached]';
                $builder = MessageBuilder::new()
                    ->setContent("Role deleted: `{$roleName}`" . PHP_EOL . "ID: {$role->id}");
                $this->logEvent($role->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['GUILD_ROLE_UPDATE']) && isset($eventKeys['GUILD_ROLE_UPDATE'])) {
            $this->event_listeners['GUILD_ROLE_UPDATE'] = function (Role $newRole, $oldRole) {
                /** @var Role|Object $oldRole Only the guild_id and role_id are guaranteed */
                $oldRoleName = $oldRole->name ?? '[Name not cached]';
                $builder = MessageBuilder::new()
                    ->setContent("Role updated from: `{$oldRoleName}`" . PHP_EOL . "to: `{$newRole->name}`");
                $this->logEvent($newRole->guild_id, $builder);
            };
        }

        if (!isset($this->event_listeners['MESSAGE_DELETE']) && isset($eventKeys['MESSAGE_DELETE'])) {
            $this->event_listeners['MESSAGE_DELETE'] = function ($message) {
                /** @var Message|Object $message Only the id, channel_id, and optionally guild_id are guaranteed  */
                $content = $message->content ?? '[Content not cached]';
                $author = $message instanceof Message ? " by {$message->author->username}" : '';
                $attachments = $message instanceof Message && !empty($message->attachments) ? PHP_EOL . "Attachments: " . implode(', ', array_map(fn($attachment) => $attachment->url, $message->attachments->toArray())) : '';
                $repliedTo = $message instanceof Message && $message->referenced_message ? PHP_EOL . "Replied to: {$message->referenced_message->content}" : '';
                $builder = MessageBuilder::new()
                    ->setContent("Message deleted (ID: {$message->id}){$author}: {$content}{$attachments}{$repliedTo}");
                $this->logEvent($message->guild_id, $builder);
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