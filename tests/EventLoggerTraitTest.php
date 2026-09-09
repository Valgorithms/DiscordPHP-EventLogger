<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP EventLogger project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace EventLogger\Tests;

use EventLogger\EventLoggerTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the trait's pure helpers through a bare host class, so nothing
 * touches the Discord client.
 *
 * @covers \EventLogger\EventLoggerTrait
 */
final class EventLoggerTraitTest extends TestCase
{
    private object $logger;

    protected function setUp(): void
    {
        $this->logger = new class () {
            use EventLoggerTrait;
        };
    }

    public function testDiffMarksChangedAddedAndRemovedLines(): void
    {
        $out = $this->logger->diff("one\ntwo\nthree", "one\nTWO\nthree\nfour");

        $this->assertStringStartsWith("```diff\n", $out['old']);
        $this->assertStringContainsString('- two', $out['old'], 'a changed line is marked removed on the old side');
        $this->assertStringContainsString("\n one", $out['old'], 'an unchanged line is kept with a leading space');
        $this->assertStringContainsString('+ TWO', $out['new'], 'and added on the new side');
        $this->assertStringContainsString('+ four', $out['new'], 'a wholly new trailing line is added');
        $this->assertStringEndsWith("\n```", $out['new']);
    }

    public function testRemoveRedundantPropertiesDropsEditedTimestamp(): void
    {
        $in = ['content' => ['new' => 'b', 'old' => 'a'], 'edited_timestamp' => ['new' => 't2', 'old' => 't1']];

        $this->assertSame(['content'], array_keys($this->logger::removeRedundantProperties($in)));
    }

    public function testDescribeDifferencesRendersAMessageContentEdit(): void
    {
        $text = $this->logger->describeDifferences([
            'old' => "```diff\n- hi\n```",
            'new' => "```diff\n+ hey\n```",
        ]);

        $this->assertStringContainsString('**Before**', $text);
        $this->assertStringContainsString('**After**', $text);
        $this->assertStringContainsString('- hi', $text);
        $this->assertStringContainsString('+ hey', $text);
    }

    public function testDescribeDifferencesRendersScalarAndCollectionChanges(): void
    {
        $text = $this->logger->describeDifferences([
            'name' => ['new' => 'General', 'old' => 'general'],
            'topic' => ['new' => 'rules', 'old' => null],
            'roles' => ['added' => ['r1'], 'removed' => []],
        ]);

        $this->assertStringContainsString('**name**: `general` → `General`', $text);
        $this->assertStringContainsString('**topic**: `—` → `rules`', $text);
        $this->assertStringContainsString('**roles** added: ["r1"]', $text);
        $this->assertStringNotContainsString('removed', $text, 'an empty removed list is not rendered');
    }

    public function testDescribeDifferencesIsEmptyForNoChanges(): void
    {
        $this->assertSame('', $this->logger->describeDifferences([]));
    }

    public function testAddLogChannelRejectsNonNumericIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->logger->addLogChannel('not-a-number', '123');
    }

    public function testAddAndRemoveLogChannelRoundTrip(): void
    {
        $this->logger->addLogChannel('111', '222');
        $ref = new \ReflectionProperty($this->logger, 'log_channel_ids');
        $this->assertSame(['111' => '222'], $ref->getValue($this->logger));

        $this->logger->removeLogGuild('111');
        $this->assertSame([], $ref->getValue($this->logger));
    }

    public function testGetLogChannelsFromEnvParsesPairsAndIgnoresJunk(): void
    {
        putenv('DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS= 111-222 , , 333-444 ,bad,555-');
        try {
            $ref = new \ReflectionMethod($this->logger, 'getLogChannelsFromEnv');
            $ref->invoke($this->logger);
        } finally {
            putenv('DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS');
        }

        $prop = new \ReflectionProperty($this->logger, 'log_channel_ids');
        $this->assertSame(['111' => '222', '333' => '444'], $prop->getValue($this->logger));
    }

    public function testGetLogChannelsFromEnvIsANoOpWhenUnset(): void
    {
        putenv('DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS');

        $ref = new \ReflectionMethod($this->logger, 'getLogChannelsFromEnv');
        $ref->invoke($this->logger);

        $prop = new \ReflectionProperty($this->logger, 'log_channel_ids');
        $this->assertSame([], $prop->getValue($this->logger));
    }

    public function testGetPartDifferencesReturnsEmptyWithNoOldPart(): void
    {
        $this->assertSame([], $this->logger->getPartDifferences((object) ['a' => 1], null));
    }
}
