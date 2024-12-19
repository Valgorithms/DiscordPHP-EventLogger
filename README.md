# DiscordPHP EventLogger

DiscordPHP EventLogger is a tool designed to generate user logs for the DiscordPHP API Library. It logs various events such as member joins/leaves, message deletions, role updates, and more.

## Features

- Logs member joins and leaves
- Logs message deletions and updates
- Logs role creations, deletions, and updates
- Logs channel creations, deletions, and updates
- Logs bans and unbans

## Installation

To install the DiscordPHP EventLogger, you need to have Composer installed. Run the following command:

```bash
composer require valgorithms/discord-php-eventlogger
```

## Usage

### Configuration

Set the `DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS` environment variable to specify the guilds and their respective log channels. The value should be a comma-separated string where each entry is a guild-channel pair separated by a hyphen.

Example:
```
DISCORDPHP_EVENTLOGGER_GUILD_CHANNELS=1077144430588469349-1077144432463314998,1253459964849164328-1253480680583860367
```

### Example

Here is an example of how to use the EventLogger in your project:

```php
require 'vendor/autoload.php';

use Discord\Discord;
use EventLogger\EventLogger;

$discord = new Discord([
    'token' => 'YOUR_DISCORD_BOT_TOKEN',
]);

$events = [
    'CHANNEL_CREATE',
    'CHANNEL_DELETE',
    'CHANNEL_UPDATE',
    'GUILD_BAN_ADD',
    'GUILD_BAN_REMOVE',
    'GUILD_MEMBER_ADD',
    'GUILD_MEMBER_REMOVE',
    'GUILD_MEMBER_UPDATE',
    'MESSAGE_DELETE',
    'GUILD_ROLE_CREATE',
    'GUILD_ROLE_DELETE',
    'GUILD_ROLE_UPDATE',
];

$eventLogger = new EventLogger($discord, $events);

$discord->run();
```

### Custom Events

You can add custom events by modifying the `createDefaultEventListeners` method in the `EventLoggerTrait` trait.

```php
private function createDefaultEventListeners(array $events = []): void
{
    // Add your custom event listeners here
}
```

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE.md) file for details.

## Credits

DiscordPHP EventLogger by Valithor Obsidion
