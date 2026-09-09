# DiscordPHP EventLogger

DiscordPHP EventLogger is a drop-in audit logger for the [DiscordPHP](https://github.com/discord-php/DiscordPHP) library. It listens for gateway events and posts a formatted embed to each guild's configured log channel.

## Features

- Member joins / leaves / updates (nickname, roles, avatar)
- Message deletions and edits (a line-by-line `diff` for edits, cached-content aware)
- Role creations / deletions / updates
- Channel creations / deletions / updates
- Bans and unbans
- Opt-in `USER_UPDATE`
- Per-guild log channels, from an env var or set at runtime
- Any default handler can be overridden with your own callable

## Requirements

- PHP 8.3+
- `team-reflex/discord-php` ^10

## Installation

```bash
composer require valgorithms/discord-php-eventlogger
```

## Usage

```php
require 'vendor/autoload.php';

use Discord\Discord;
use EventLogger\EventLogger;

$discord = new Discord(['token' => 'YOUR_DISCORD_BOT_TOKEN']);

// Default event set (EventLogger::DEFAULT_EVENTS):
$logger = new EventLogger($discord);

// Or pick the events, and override a handler with your own callable
// (it receives the gateway payload plus the Discord client):
$logger = new EventLogger($discord, [
    'MESSAGE_DELETE',
    'GUILD_MEMBER_ADD',
    'GUILD_BAN_ADD' => fn(\Discord\Parts\Guild\Ban $ban, Discord $discord) =>
        $discord->getLogger()->notice("Banned {$ban->user} in {$ban->guild_id}"),
]);

$discord->run();
```

### Configuring log channels

Set `DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS` to a comma-separated list of
`guildId-channelId` pairs:

```
DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS=1077144430588469349-1077144432463314998,1253459964849164328-1253480680583860367
```

Blank / malformed entries are ignored, and an unset variable is fine — add
channels at runtime instead:

```php
$logger->addLogChannel('1077144430588469349', '1077144432463314998');
$logger->removeLogGuild('1077144430588469349');
```

## Development

```bash
composer install
composer unit   # phpunit
composer pint   # code style
```

## License

MIT — see [LICENSE.md](LICENSE.md).

## Credits

DiscordPHP EventLogger by Valithor Obsidion
