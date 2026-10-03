<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EventLogger\Tests;

use Discord\Discord;
use Discord\Http\Endpoint;
use Discord\Http\Http;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Guild\AuditLog\Entry;
use Discord\Parts\Guild\Ban;
use Discord\Parts\User\Member;
use EventLogger\EventLoggerTrait;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * How moderation events find the audit log entry that says who acted and why.
 *
 * @covers \EventLogger\EventLoggerTrait
 */
final class AuditLogTest extends TestCase
{
    private const float NOW = 1790000000.0;

    private object $logger;

    /** @var array<string, callable> Listeners the logger registered on the mocked client. */
    private array $listeners = [];

    protected function setUp(): void
    {
        $this->logger = new class () {
            use EventLoggerTrait;
        };
    }

    public function testMatchTakesTheNewestRecentEntryAgainstTheTarget(): void
    {
        $entries = [
            entry('1', Entry::MEMBER_BAN_ADD, '999', self::NOW - 1),
            entry('2', Entry::MEMBER_BAN_ADD, '42', self::NOW - 2, 'spam'),
            entry('3', Entry::MEMBER_BAN_ADD, '42', self::NOW - 5, 'older'),
        ];

        $match = $this->logger::matchAuditLogEntry($entries, [Entry::MEMBER_BAN_ADD], '42', self::NOW);

        $this->assertSame('spam', $match?->reason);
    }

    public function testMatchIgnoresOtherActionTypes(): void
    {
        $entries = [entry('1', Entry::MEMBER_KICK, '42', self::NOW)];

        $this->assertNull($this->logger::matchAuditLogEntry($entries, [Entry::MEMBER_BAN_ADD], '42', self::NOW));
        $this->assertNotNull($this->logger::matchAuditLogEntry($entries, [Entry::MEMBER_KICK, Entry::MEMBER_BAN_ADD], '42', self::NOW));
    }

    public function testMatchRejectsAnEntryTooOldToBeThisEvent(): void
    {
        // A kick long ago must not turn today's voluntary leave into a kick.
        $entries = [entry('1', Entry::MEMBER_KICK, '42', self::NOW - 3600)];

        $this->assertNull($this->logger::matchAuditLogEntry($entries, [Entry::MEMBER_KICK], '42', self::NOW));
    }

    public function testDescribeAddsTheModeratorAndReason(): void
    {
        $line = $this->logger::describeAuditLogEntry('User banned: <@42>', entry('1', Entry::MEMBER_BAN_ADD, '42', self::NOW, 'spam'));

        $this->assertSame("User banned: <@42>\nBy: <@7>\nReason: spam", str_replace(PHP_EOL, "\n", $line));
    }

    public function testDescribeSaysWhenNoReasonWasGiven(): void
    {
        $line = $this->logger::describeAuditLogEntry('User banned: <@42>', entry('1', Entry::MEMBER_BAN_ADD, '42', self::NOW));

        $this->assertStringEndsWith('Reason: No reason given', $line);
    }

    public function testDescribeLeavesTheLineAloneWithoutAnEntry(): void
    {
        $this->assertSame('User banned: <@42>', $this->logger::describeAuditLogEntry('User banned: <@42>', null));
    }

    public function testFindQueriesTheGuildsAuditLogForTheActionType(): void
    {
        $url = null;
        $discord = $this->discordReturning(function ($endpoint) use (&$url) {
            $url = (string) $endpoint;

            return resolve((object) ['audit_log_entries' => [entry('1', Entry::MEMBER_BAN_ADD, '42', microtime(true), 'spam')]]);
        });

        $found = null;
        $this->logger->findAuditLogEntry($discord, '100', [Entry::MEMBER_BAN_ADD], '42')->then(function ($entry) use (&$found) {
            $found = $entry;
        });

        $this->assertSame('spam', $found?->reason);
        $this->assertStringStartsWith('guilds/100/audit-logs?', $url);
        $this->assertStringContainsString('action_type=' . Entry::MEMBER_BAN_ADD, $url);
    }

    public function testFindRetriesOnceWhenTheEntryIsNotYetWritten(): void
    {
        $calls = 0;
        $discord = $this->discordReturning(function () use (&$calls) {
            $calls++;

            return resolve((object) ['audit_log_entries' => $calls === 1 ? [] : [entry('1', Entry::MEMBER_BAN_ADD, '42', microtime(true), 'late')]]);
        });

        $found = null;
        $this->logger->findAuditLogEntry($discord, '100', [Entry::MEMBER_BAN_ADD], '42')->then(function ($entry) use (&$found) {
            $found = $entry;
        });

        $this->assertSame(2, $calls);
        $this->assertSame('late', $found?->reason);
    }

    public function testFindResolvesNullWithoutAuditLogAccess(): void
    {
        $discord = $this->discordReturning(fn () => reject(new \RuntimeException('403 Missing Permissions')));

        $resolved = false;
        $this->logger->findAuditLogEntry($discord, '100', [Entry::MEMBER_BAN_ADD], '42')->then(function ($entry) use (&$resolved) {
            $resolved = $entry === null;
        });

        $this->assertTrue($resolved);
    }

    public function testAKickedMemberIsLoggedAsKickedWithModeratorAndReason(): void
    {
        $urls = [];
        $logged = $this->runListener('GUILD_MEMBER_REMOVE', $this->part(Member::class, ['guild_id' => '100', 'id' => '42', 'user' => '<@42>']), function ($endpoint) use (&$urls) {
            $urls[] = $url = (string) $endpoint;

            return resolve((object) ['audit_log_entries' => str_contains($url, 'action_type=' . Entry::MEMBER_KICK)
                ? [entry('1', Entry::MEMBER_KICK, '42', microtime(true), 'rude')]
                : []]);
        });

        $this->assertSame(['GUILD_MEMBER_REMOVE', '100', "Member kicked: <@42>\nBy: <@7>\nReason: rude"], $logged);
        // Kicks and bans are looked up separately, so neither can be crowded out by the other.
        $this->assertCount(2, $urls);
        $this->assertStringContainsString('action_type=' . Entry::MEMBER_KICK, $urls[0]);
        $this->assertStringContainsString('action_type=' . Entry::MEMBER_BAN_ADD, $urls[1]);
    }

    public function testAMemberRemovedByABanIsNotLoggedAsKicked(): void
    {
        $logged = $this->runListener('GUILD_MEMBER_REMOVE', $this->part(Member::class, ['guild_id' => '100', 'id' => '42', 'user' => '<@42>']), fn ($endpoint) => resolve((object) [
            'audit_log_entries' => str_contains((string) $endpoint, 'action_type=' . Entry::MEMBER_BAN_ADD)
                ? [entry('1', Entry::MEMBER_BAN_ADD, '42', microtime(true), 'spam')]
                : [],
        ]));

        $this->assertSame(['GUILD_MEMBER_REMOVE', '100', 'Member removed by a ban: <@42>'], $logged);
    }

    public function testAMemberWithNoMatchingEntryIsLoggedAsLeaving(): void
    {
        // Another member's kick is no reason to call this one kicked.
        $logged = $this->runListener('GUILD_MEMBER_REMOVE', $this->part(Member::class, ['guild_id' => '100', 'id' => '42', 'user' => '<@42>']), fn () => resolve((object) [
            'audit_log_entries' => [entry('1', Entry::MEMBER_KICK, '999', microtime(true))],
        ]));

        $this->assertSame(['GUILD_MEMBER_REMOVE', '100', 'Member left: <@42>'], $logged);
    }

    public function testABanIsLoggedWithModeratorAndReason(): void
    {
        $urls = [];
        $logged = $this->runListener('GUILD_BAN_ADD', $this->part(Ban::class, ['guild_id' => '100', 'user_id' => '42', 'user' => '<@42>']), function ($endpoint) use (&$urls) {
            $urls[] = (string) $endpoint;

            return resolve((object) ['audit_log_entries' => [entry('1', Entry::MEMBER_BAN_ADD, '42', microtime(true), 'spam')]]);
        });

        $this->assertSame(['GUILD_BAN_ADD', '100', "User banned: <@42>\nBy: <@7>\nReason: spam"], $logged);
        $this->assertSame(['guilds/100/audit-logs?action_type=' . Entry::MEMBER_BAN_ADD . '&limit=10'], $urls);
    }

    /**
     * Wires the default listener for `$event` to a Discord client whose audit log answers with `$get`,
     * fires it with `$part`, and returns the event, guild and line it logged.
     *
     * @return array{string, string, string}|null
     */
    private function runListener(string $event, object $part, callable $get): ?array
    {
        $host = new class () {
            use EventLoggerTrait;

            public ?array $logged = null;

            public function logEvent(Discord $discord, string $event, string $guild_id, object|string $content, ?object $old_content = null, ?MessageBuilder $builder = null): PromiseInterface
            {
                $this->logged = [$event, $guild_id, str_replace(PHP_EOL, "\n", (string) $content)];

                return resolve(null);
            }
        };

        $discord = $this->discordReturning($get);
        $host->afterConstruct($discord, [$event]);
        ($this->listeners[$event])($part, $discord);

        return $host->logged;
    }

    /**
     * A gateway part whose attributes read as `$attributes`.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function part(string $class, array $attributes): object
    {
        $part = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['__get'])->getMock();
        $part->method('__get')->willReturnCallback(fn (string $key) => $attributes[$key] ?? null);

        return $part;
    }

    /**
     * A Discord client whose HTTP GET answers with `$get`, and whose loop runs timers at once.
     */
    private function discordReturning(callable $get): Discord
    {
        $http = $this->getMockBuilder(Http::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
        $http->method('get')->willReturnCallback(fn (Endpoint $endpoint) => $get($endpoint));

        $loop = $this->getMockBuilder(LoopInterface::class)->getMock();
        $loop->method('addTimer')->willReturnCallback(function ($interval, callable $callback) {
            $callback();

            return $this->getMockBuilder(TimerInterface::class)->getMock();
        });

        $discord = $this->getMockBuilder(Discord::class)->disableOriginalConstructor()->onlyMethods(['getHttpClient', 'getLoop', 'on'])->getMock();
        $discord->method('getHttpClient')->willReturn($http);
        $discord->method('getLoop')->willReturn($loop);
        $discord->method('on')->willReturnCallback(function (string $event, callable $listener) use ($discord) {
            $this->listeners[$event] = $listener;

            return $discord;
        });

        return $discord;
    }
}

// Helpers

/**
 * A raw audit log entry, as Discord returns it, whose snowflake id carries `$at`.
 */
function entry(string $seq, int $action_type, string $target_id, float $at, ?string $reason = null): object
{
    $id = (string) ((((int) ($at * 1000)) - 1420070400000) << 22 | (int) $seq);

    return (object) ['id' => $id, 'action_type' => $action_type, 'target_id' => $target_id, 'user_id' => '7', 'reason' => $reason];
}
