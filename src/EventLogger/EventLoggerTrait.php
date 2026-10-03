<?php

declare(strict_types=1);

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
use Discord\Http\Endpoint;
use Discord\Parts\Guild\AuditLog\Entry;
use Discord\Parts\Guild\Ban;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use EmbedBuilder\EmbedBuilder;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

trait EventLoggerTrait
{
    /** Attributes that change on every edit and carry no signal — dropped from a diff. */
    private const array REDUNDANT_PROPERTIES = [
        'edited_timestamp',
    ];

    private const string GITHUB = 'https://github.com/valgorithms/discordphp-eventlogger';
    private const string CREDITS = 'DiscordPHP EventLogger by Valithor Obsidion';
    private const string ENV_GUILD_CHANNELS = 'DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS';
    /** Milliseconds from the Unix epoch to Discord's, for reading a snowflake's timestamp. */
    private const int DISCORD_EPOCH = 1420070400000;
    /** Seconds an audit log entry may predate its gateway event and still be taken as its cause. */
    private const int AUDIT_LOG_MAX_AGE = 15;
    /** Seconds to wait before looking for an audit log entry a second time. */
    private const float AUDIT_LOG_RETRY_DELAY = 1.5;
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
    public function afterConstruct(Discord $discord, array $events): void
    {
        if ($this->setup) {
            return;
        }
        if (!isset($this->discord)) {
            $this->discord = $discord;
        }
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
    ): void {
        if (! is_numeric($guild_id) || ! is_numeric($channel_id)) {
            throw new \InvalidArgumentException('Guild ID and Channel ID must be numeric.');
        }
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
    ): void {
        unset($this->log_channel_ids[$guild_id]);
    }

    public function getPartDifferences(object $newPart, ?object $oldPart): array
    {
        if (! $oldPart) {
            return [];
        }
        if ($newPart instanceof Message) {
            return $this->handleMessages($newPart, $this->discord, $oldPart instanceof Message ? $oldPart : null);
        }
        if (!method_exists($newPart, 'getRawAttributes') || !method_exists($oldPart, 'getRawAttributes')) {
            return [];
        }

        $differences = [];

        $newAttributes = $newPart->getRawAttributes();
        $oldAttributes = $oldPart->getRawAttributes();

        foreach ($newAttributes as $key => $newValue) {
            if (array_key_exists($key, $oldAttributes)) {
                $oldValue = $oldAttributes[$key];

                if (is_array($newValue) && is_array($oldValue)) {
                    $addedItems = array_diff($newValue, $oldValue);
                    $removedItems = array_diff($oldValue, $newValue);
                    if (!empty($addedItems) || !empty($removedItems)) {
                        $differences[$key] = ['added' => $addedItems, 'removed' => $removedItems];
                    }
                } elseif ($newValue instanceof \ArrayAccess && $oldValue instanceof \ArrayAccess) {
                    /** @var Collection $newValue */
                    /** @var Collection $oldValue */
                    $newItems = method_exists($newValue, 'getIterator') ? iterator_to_array($newValue->getIterator()) : iterator_to_array($newValue);
                    $oldItems = method_exists($oldValue, 'getIterator') ? iterator_to_array($oldValue->getIterator()) : iterator_to_array($oldValue);
                    $addedItems = array_diff($newItems, $oldItems);
                    $removedItems = array_diff($oldItems, $newItems);
                    if (!empty($addedItems) || !empty($removedItems)) {
                        $differences[$key] = ['added' => $addedItems, 'removed' => $removedItems];
                    }
                } elseif (is_object($newValue) && is_object($oldValue)) {
                    $nestedDifferences = $this->getPartDifferences($newValue, $oldValue);
                    if (!empty($nestedDifferences)) {
                        $differences[$key] = $nestedDifferences;
                    }
                } elseif ($newValue !== $oldValue) {
                    $differences[$key] = ['new' => $newValue, 'old' => $oldValue];
                }
            } else {
                $differences[$key] = ['new' => $newValue, 'old' => null];
            }
        }

        foreach ($oldAttributes as $key => $oldValue) {
            if (!array_key_exists($key, $newAttributes)) {
                $differences[$key] = ['new' => null, 'old' => $oldValue];
            }
        }

        return self::removeRedundantProperties($differences);
    }

    /**
     * @return array{old?: string, new?: string} the rendered before/after diff, or `[]` when there is nothing to log
     */
    public function handleMessages(mixed $message, Discord $discord, ?Message $oldMessage): array
    {
        if (
            ! $message instanceof Message ||
            ($message->author?->id ?? null) === $discord->id ||
            ($message->author?->bot ?? false) ||
            ! $message->guild
        ) {
            return [];
        }

        if (! $oldMessage || trim((string) $message->content) === trim((string) $oldMessage->content)) {
            return [];
        }

        return $this->diff((string) $oldMessage->content, (string) $message->content);
    }

    /**
     * A line-by-line ` `/`-`/`+` diff of two message bodies, each side wrapped
     * in a ```diff block. Splits on `\n` (Discord's line ending), not PHP_EOL,
     * so it renders identically on every platform.
     *
     * @return array{old: string, new: string}
     */
    public function diff(string $before, string $after): array
    {
        $split = static fn (string $s): array => array_map('trim', preg_split('/\r\n|\r|\n/', trim($s)) ?: ['']);
        $beforeLines = $split($before);
        $afterLines = $split($after);

        $beforeDiff = array_map(
            static fn ($line, $index) =>
            isset($afterLines[$index])
                ? ($line === $afterLines[$index] ? " {$line}" : "- {$line}")
                : "- {$line}",
            $beforeLines,
            array_keys($beforeLines)
        );

        $afterDiff = array_map(
            static fn ($line, $index) =>
            isset($beforeLines[$index])
                ? ($line === $beforeLines[$index] ? " {$line}" : "+ {$line}")
                : "+ {$line}",
            $afterLines,
            array_keys($afterLines)
        );

        return [
            'old' => "```diff\n" . implode("\n", $beforeDiff) . "\n```",
            'new' => "```diff\n" . implode("\n", $afterDiff) . "\n```",
        ];
    }


    public static function removeRedundantProperties(array $array): array
    {
        return array_diff_key($array, array_flip(self::REDUNDANT_PROPERTIES));
    }

    public function getDifferences($newObject, $oldObject): array
    {
        return $this->getPartDifferences($newObject, $oldObject);
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
        ?object $old_content = null,
        ?MessageBuilder $builder = null
    ): PromiseInterface {
        if (! $channel_id = $this->log_channel_ids[$guild_id] ?? null) {
            return reject(new \Exception('Discord Channel ID not configured'));
        }
        if (! $guild = $discord->guilds->get('id', $guild_id)) {
            return reject(new \Exception('Discord Guild not found'));
        }
        if (! $channel = $guild->channels->get('id', $channel_id)) {
            return reject(new \Exception('Discord Channel not found'));
        }
        if (! $builder) {
            $builder = MessageBuilder::new();
        }

        $differences = $this->getDifferences($content, $old_content);
        $discord->getLogger()->info("Logging event: $event, Guild ID: {$guild_id}, Differences: " . json_encode($differences), [
            'event' => $event,
            'guild_id' => $guild_id,
            'differences' => $differences
        ]);

        if (is_string($content)) {
            return $channel->sendMessage($builder->setContent($content));
        }

        $description = $this->describeDifferences($differences);

        if (! $description) {
            return reject(new \Exception('No content to log'));
        }
        if (strlen($description) <= 4096) {
            return $channel->sendMessage($builder->addEmbed(EmbedBuilder::new($discord, $this->color, $this->footer)->setDescription($description)->setTitle($event)));
        }
        return $channel->sendMessage($builder->addFileFromContent("$event.txt", $description));
    }

    /**
     * Renders the {@see getDifferences()} map to an embed description. Handles the
     * three shapes it produces: a `{old, new}` message-content diff, a
     * `{added, removed}` collection change, and a plain `{new, old}` scalar change.
     *
     * @param array<string, mixed> $differences
     */
    public function describeDifferences(array $differences): string
    {
        if ($differences === []) {
            return '';
        }

        // A message-content edit: diff() returns pre-formatted ```diff blocks.
        if (isset($differences['old'], $differences['new']) && is_string($differences['old']) && is_string($differences['new'])) {
            return '**Before**' . PHP_EOL . $differences['old'] . PHP_EOL . '**After**' . PHP_EOL . $differences['new'];
        }

        $lines = [];
        foreach ($differences as $key => $diff) {
            if (! is_array($diff)) {
                $lines[] = "**{$key}**" . PHP_EOL . (string) $diff;
                continue;
            }
            if (! empty($diff['added'])) {
                $lines[] = "**{$key}** added: " . json_encode(array_values((array) $diff['added']));
            }
            if (! empty($diff['removed'])) {
                $lines[] = "**{$key}** removed: " . json_encode(array_values((array) $diff['removed']));
            }
            if (array_key_exists('new', $diff) && array_key_exists('old', $diff)) {
                $lines[] = "**{$key}**: `" . self::scalar($diff['old']) . '` → `' . self::scalar($diff['new']) . '`';
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: get_debug_type($value);
    }

    /**
     * Finds the audit log entry for a moderation action that just happened, so its log line can say who
     * did it and why. Resolves null when there is none, or when the bot lacks View Audit Log.
     *
     * Discord can deliver the gateway event before the entry is readable, so a miss is retried once
     * after {@see AUDIT_LOG_RETRY_DELAY} seconds.
     *
     * @param list<int> $action_types Entry action types to accept (Entry::MEMBER_BAN_ADD, ...).
     *
     * @return PromiseInterface<object|null> The raw audit log entry.
     */
    public function findAuditLogEntry(Discord $discord, string $guild_id, array $action_types, string $target_id, bool $retry = true): PromiseInterface
    {
        // One request per action type: an unfiltered page of recent entries could be filled by unrelated
        // actions in a busy guild, crowding out the kick or ban being looked for.
        $requests = array_map(function (int $action_type) use ($discord, $guild_id): PromiseInterface {
            $endpoint = Endpoint::bind(Endpoint::AUDIT_LOG, $guild_id);
            $endpoint->addQuery('action_type', $action_type);
            $endpoint->addQuery('limit', 10);

            return $discord->getHttpClient()->get($endpoint);
        }, $action_types);

        return all($requests)->then(
            function (array $responses) use ($discord, $guild_id, $action_types, $target_id, $retry): PromiseInterface {
                $entries = array_merge(...array_map(static fn ($response) => (array) ($response->audit_log_entries ?? []), $responses));
                // Newest first across all the responses, as matchAuditLogEntry() expects.
                usort($entries, static fn (object $a, object $b) => ((int) $b->id) <=> ((int) $a->id));
                $entry = self::matchAuditLogEntry($entries, $action_types, $target_id);
                if ($entry || ! $retry) {
                    return resolve($entry);
                }

                $deferred = new Deferred();
                $discord->getLoop()->addTimer(self::AUDIT_LOG_RETRY_DELAY, fn () => $deferred->resolve(
                    $this->findAuditLogEntry($discord, $guild_id, $action_types, $target_id, false)
                ));

                return $deferred->promise();
            },
            fn (\Throwable $e) => null
        );
    }

    /**
     * The newest entry of one of the action types against the target, if it is recent enough to belong
     * to the event being logged rather than an earlier one against the same user.
     *
     * @param array<object>  $entries      Raw `audit_log_entries`, newest first.
     * @param list<int>      $action_types
     * @param float|null     $now          Unix time in seconds; defaults to now.
     */
    public static function matchAuditLogEntry(array $entries, array $action_types, string $target_id, ?float $now = null): ?object
    {
        $now ??= microtime(true);

        foreach ($entries as $entry) {
            if (($entry->target_id ?? null) !== $target_id || ! in_array($entry->action_type ?? null, $action_types, true)) {
                continue;
            }
            $created = ((((int) $entry->id) >> 22) + self::DISCORD_EPOCH) / 1000;

            return $now - $created <= self::AUDIT_LOG_MAX_AGE ? $entry : null;
        }

        return null;
    }

    /**
     * Appends who took the action and their reason to a log line.
     */
    public static function describeAuditLogEntry(string $line, ?object $entry): string
    {
        if (! $entry) {
            return $line;
        }
        if ($executor = $entry->user_id ?? null) {
            $line .= PHP_EOL . "By: <@{$executor}>";
        }

        return $line . PHP_EOL . 'Reason: ' . (($entry->reason ?? '') !== '' ? $entry->reason : 'No reason given');
    }

    /**
     * Whether a member left, was kicked or was removed by a ban, from the audit log entry that
     * removed them, if any.
     */
    public static function describeMemberRemoval(string $user, ?object $entry): string
    {
        return match ($entry?->action_type) {
            Entry::MEMBER_KICK => self::describeAuditLogEntry("Member kicked: {$user}", $entry),
            Entry::MEMBER_BAN_ADD => "Member removed by a ban: {$user}",
            default => "Member left: {$user}",
        };
    }

    /**
     * Logs a moderation event, with the moderator and reason from the audit log when it has them.
     *
     * @param callable(?object): string $describe Builds the log line from the matching entry, or null.
     */
    private function logModerationEvent(Discord $discord, string $event, string $guild_id, array $action_types, string $target_id, callable $describe): PromiseInterface
    {
        return $this->findAuditLogEntry($discord, $guild_id, $action_types, $target_id)->then(
            fn (?object $entry) => $this->logEvent(
                $discord,
                $event,
                $guild_id,
                $describe($entry),
                null,
                // The line mentions the moderator; don't ping them for it.
                MessageBuilder::new()->setAllowedMentions(['parse' => []])
            )
        );
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
        $raw = trim((string) getenv(self::ENV_GUILD_CHANNELS));
        if ($raw === '') {
            return;
        }

        foreach (explode(',', $raw) as $pair) {
            $parts = array_map('trim', explode('-', $pair, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $this->addLogChannel($parts[0], $parts[1]);
            }
        }
    }

    private function createDefaultEventListeners(
        array $events
    ): void {
        // `$events` mixes plain names (`['MESSAGE_DELETE', ...]`) with
        // `'EVENT' => callable` overrides. Merge the overrides in, then build
        // the "is this event wanted?" set from both — array_flip only on the
        // string names so a Closure value never trips its warning.
        $callableEvents = array_filter($events, 'is_callable');
        $this->event_listeners = array_merge($this->event_listeners, $callableEvents);
        $eventKeys = array_flip(array_values(array_filter($events, 'is_string')))
            + array_fill_keys(array_keys($callableEvents), true);

        if (!isset($this->event_listeners['CHANNEL_CREATE']) && isset($eventKeys['CHANNEL_CREATE'])) {
            $this->event_listeners['CHANNEL_CREATE'] = fn (Channel $channel, Discord $discord) => $this->logEvent(
                $discord,
                'CHANNEL_CREATE',
                $channel->guild_id,
                "Channel created: {$channel->name}"
            );
        }

        if (!isset($this->event_listeners['CHANNEL_DELETE']) && isset($eventKeys['CHANNEL_DELETE'])) {
            $this->event_listeners['CHANNEL_DELETE'] = fn (Channel $channel, Discord $discord) => $this->logEvent(
                $discord,
                'CHANNEL_DELETE',
                $channel->guild_id,
                "Channel deleted: {$channel->name}"
            );
        }

        if (!isset($this->event_listeners['CHANNEL_UPDATE']) && isset($eventKeys['CHANNEL_UPDATE'])) {
            $this->event_listeners['CHANNEL_UPDATE'] = fn (Channel $newChannel, Discord $discord, ?Channel $oldChannel) => $this->logEvent(
                $discord,
                'CHANNEL_UPDATE',
                $newChannel->guild_id,
                $newChannel,
                $oldChannel
            );
        }

        if (!isset($this->event_listeners['GUILD_BAN_ADD']) && isset($eventKeys['GUILD_BAN_ADD'])) {
            $this->event_listeners['GUILD_BAN_ADD'] = fn (Ban $ban, Discord $discord) => $this->logModerationEvent(
                $discord,
                'GUILD_BAN_ADD',
                $ban->guild_id,
                [Entry::MEMBER_BAN_ADD],
                $ban->user_id,
                fn (?object $entry) => self::describeAuditLogEntry("User banned: {$ban->user}", $entry)
            );
        }

        if (!isset($this->event_listeners['GUILD_BAN_REMOVE']) && isset($eventKeys['GUILD_BAN_REMOVE'])) {
            $this->event_listeners['GUILD_BAN_REMOVE'] = fn (Ban $ban, Discord $discord) => $this->logModerationEvent(
                $discord,
                'GUILD_BAN_REMOVE',
                $ban->guild_id,
                [Entry::MEMBER_BAN_REMOVE],
                $ban->user_id,
                fn (?object $entry) => self::describeAuditLogEntry("User unbanned: {$ban->user}", $entry)
            );
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_ADD']) && isset($eventKeys['GUILD_MEMBER_ADD'])) {
            $this->event_listeners['GUILD_MEMBER_ADD'] = fn (Member $member, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_MEMBER_ADD',
                $member->guild_id,
                "Member joined: {$member->user}"
            );
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_REMOVE']) && isset($eventKeys['GUILD_MEMBER_REMOVE'])) {
            // A kick or a ban also removes the member; the audit log tells them apart from leaving.
            $this->event_listeners['GUILD_MEMBER_REMOVE'] = fn (Member $member, Discord $discord) => $this->logModerationEvent(
                $discord,
                'GUILD_MEMBER_REMOVE',
                $member->guild_id,
                [Entry::MEMBER_KICK, Entry::MEMBER_BAN_ADD],
                $member->id,
                fn (?object $entry) => self::describeMemberRemoval((string) $member->user, $entry)
            );
        }

        if (!isset($this->event_listeners['GUILD_MEMBER_UPDATE']) && isset($eventKeys['GUILD_MEMBER_UPDATE'])) {
            $this->event_listeners['GUILD_MEMBER_UPDATE'] = fn (Member $newMember, Discord $discord, ?Member $oldMember) => $this->logEvent(
                $discord,
                'GUILD_MEMBER_UPDATE',
                $newMember->guild_id,
                $newMember,
                $oldMember
            );
        }

        if (!isset($this->event_listeners['GUILD_ROLE_CREATE']) && isset($eventKeys['GUILD_ROLE_CREATE'])) {
            $this->event_listeners['GUILD_ROLE_CREATE'] = fn (Role $role, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_ROLE_CREATE',
                $role->guild_id,
                "Role created: {$role->name}" . PHP_EOL . "with permissions: " . implode(', ', $role->permissions->getPermissions())
            );
        }

        if (!isset($this->event_listeners['GUILD_ROLE_DELETE']) && isset($eventKeys['GUILD_ROLE_DELETE'])) {
            $this->event_listeners['GUILD_ROLE_DELETE'] = fn (Role $role, Discord $discord) => $this->logEvent(
                $discord,
                'GUILD_ROLE_DELETE',
                $role->guild_id,
                "Role deleted: `" . ($role->name ?? '[Name not cached]') . "`" . PHP_EOL . "ID: {$role->id}"
            );
        }

        if (!isset($this->event_listeners['GUILD_ROLE_UPDATE']) && isset($eventKeys['GUILD_ROLE_UPDATE'])) {
            $this->event_listeners['GUILD_ROLE_UPDATE'] = fn (Role $newRole, Discord $discord, Role $oldRole) => $this->logEvent(
                $discord,
                'GUILD_ROLE_UPDATE',
                $newRole->guild_id,
                $newRole,
                $oldRole
            );
        }

        if (!isset($this->event_listeners['MESSAGE_UPDATE']) && isset($eventKeys['MESSAGE_UPDATE'])) {
            $this->event_listeners['MESSAGE_UPDATE'] = fn (Message $message, Discord $discord, ?Message $oldMessage) => $this->logEvent(
                $discord,
                'MESSAGE_UPDATE',
                $message->guild_id,
                $message,
                $oldMessage
            );
        }

        if (!isset($this->event_listeners['MESSAGE_DELETE']) && isset($eventKeys['MESSAGE_DELETE'])) {
            // An uncached delete arrives as a bare object with only id / channel_id / guild_id.
            $this->event_listeners['MESSAGE_DELETE'] = function (object $message, Discord $discord): ?PromiseInterface {
                $guild_id = $message->guild_id ?? null;
                if ($guild_id === null) {
                    return null;
                }

                $author = $message->author->username ?? $message->author->global_name ?? 'an unknown user';
                $content = (string) ($message->content ?? '');
                $line = "Message deleted (ID: {$message->id}) by {$author}" . ($content !== '' ? ": {$content}" : ' (content not cached)');

                $attachments = $message->attachments ?? null;
                if ($attachments !== null && (is_countable($attachments) ? count($attachments) : 0) > 0) {
                    $urls = array_map(static fn ($a) => $a->url ?? '', is_array($attachments) ? $attachments : $attachments->toArray());
                    $line .= PHP_EOL . 'Attachments: ' . implode(', ', array_filter($urls));
                }
                if (($ref = $message->referenced_message ?? null) && ($ref->content ?? '') !== '') {
                    $line .= PHP_EOL . "Replied to: {$ref->content}";
                }

                return $this->logEvent($discord, 'MESSAGE_DELETE', (string) $guild_id, $line);
            };
        }

        if (!isset($this->event_listeners['USER_UPDATE']) && isset($eventKeys['USER_UPDATE'])) {
            $this->event_listeners['USER_UPDATE'] = function (User $newUser, Discord $discord, ?User $oldUser) {
                if ($newUser->id == $discord->id) {
                    return;
                } // Ignore user updates by this bot
                foreach ($discord->guilds as $guild) {
                    if ($guild->members->get('id', $newUser->id)) {
                        $this->logEvent(
                            $discord,
                            'USER_UPDATE',
                            $guild->id,
                            $newUser,
                            $oldUser
                        );
                    }
                }
            };
        }

        // Add more event listeners as needed

        $this->createEventListeners();
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
        foreach ($this->event_listeners as $event => $listener) {
            if (is_callable($listener)) {
                $this->discord->on($event, $listener);
            }
        }
    }
}
