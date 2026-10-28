# PHPAGI 3.0.0

This project is a modernization of the original PHPAGI library for modern PHP,
with typed properties and method signatures, enums, and Composer autoloading.
Proudly modernized using Codex.

PHPAGI provides PHP interfaces for Asterisk's AGI protocol and Asterisk Manager
Interface (AMI). It includes helpers for answering calls, playing prompts,
collecting DTMF digits, reading channel variables, and handling manager events.

## Requirements

- PHP 8.1 or later. The local compatibility checks have been run on PHP 8.4.3.
- Composer 2.2 or later for dependency installation and autoload generation.
- Asterisk for actual AGI and AMI operations.
- A directory writable by the account running AGI scripts for temporary files.

Optional integrations require Festival's `text2wave` or Cepstral's `swift` binary.
FastAGI privilege switching requires PHP's POSIX extension. EAGI audio access
requires an audio stream supplied by Asterisk on file descriptor 3.

## Installation

In an application that consumes this package:

```sh
composer require sergioooo/phpagi:^3.0
```

The command requires version 3.0.0 to have been published to your configured
Composer repository. When working directly in this checkout, run:

```sh
composer install
```

Composer autoloading is required. Load `vendor/autoload.php` once in your
application entry point; library classes and the channel-state enum are loaded
on demand. Shared constants are loaded automatically, including for applications
that use only `AGI_AsteriskManager`. Direct inclusion of library source files is
not supported.

## AGI example

Place an executable PHP script in your Asterisk AGI scripts directory, adjusting
the autoloader path for your application:

```php
#!/usr/bin/env php
<?php

require '/path/to/application/vendor/autoload.php';

$agi = new AGI();
$agi->answer();
$agi->stream_file('hello-world');
$result = $agi->get_data('beep', 5000, 4);

if ($result['code'] == AGIRES_OK && $result['result'] != -1)
{
	$agi->set_variable('ENTERED_DIGITS', $result['result']);
}

$agi->hangup();
```

Invoke the script from your dialplan:

```ini
exten => 100,1,AGI(example.php)
 same => n,Hangup()
```

`new AGI()` reads the AGI request headers from standard input. Commands are written
to standard output, so application diagnostics should use standard error or a
separate logger. Most methods return an array containing `code`, `result`, and
`data`; additional fields depend on the command. Protocol values often remain
strings. `GET DATA` returns the collected digit string; digit playback methods
return the character code of the interrupting digit instead.

## Channel states

Channel states are represented by the integer-backed `AGI_ChannelState` enum.
`channel_status()` retains its response array and numeric protocol result:

```php
$status = $agi->channel_status();

if ($status['result'] == AGI_ChannelState::UP->value)
{
	// The channel is up.
}
```

Replace references such as `AST_STATE_UP` with `AGI_ChannelState::UP->value`.
The `AST_STATE_*` global constants have been removed. All ten enum cases retain
values 0 through 9. Native enums require PHP 8.1 or later.

## Manager example

Enable AMI and configure an account with the permissions your application needs
in Asterisk's `manager.conf`. Supply that account's credentials to PHPAGI:

```php
<?php

require '/path/to/application/vendor/autoload.php';

$manager = new AGI_AsteriskManager(null, [
	'server' => '127.0.0.1',
	'port' => 5038,
	'username' => 'application',
	'secret' => getenv('ASTERISK_AMI_SECRET'),
]);

if (!$manager->connect())
{
	throw new RuntimeException('Could not connect to Asterisk Manager');
}

try
{
	$response = $manager->Ping();
}
finally
{
	$manager->disconnect();
}
```

Register event callbacks with `add_event_handler()`:

```php
$manager->add_event_handler('Hangup', function ($event, $parameters, $server, $port) {
	error_log('Channel hung up: ' . ($parameters['Channel'] ?? 'unknown'));
});
```

`wait_response()` dispatches events while waiting for a response. When called
without a pending request, it can wait indefinitely. `wait_single_response()`
returns after one packet, which can be an event rather than a command response;
it does not configure a socket timeout.

## Configuration

Both classes accept an optional INI file path as their first constructor argument.
If that file is absent, they look for `/etc/asterisk/phpagi.conf`. The second
argument supplies overrides for the `[phpagi]` or `[asmanager]` section,
respectively.

```ini
[phpagi]
debug = false
tempdir = /var/spool/asterisk/tmp

[asmanager]
server = 127.0.0.1
port = 5038
username = application
secret = replace-with-your-AMI-secret

[festival]
text2wave = /usr/bin/text2wave

[cepstral]
swift = /opt/swift/bin/swift
voice = default

[fastagi]
basedir = /var/lib/asterisk/agi-bin
setuid = false
```

The library attempts to locate TTS binaries in `PATH` when their paths are omitted.
PHP error handling belongs to the application. The library does not install an
error handler, change `error_reporting()`, or email PHP errors. Configure PHP error
reporting and any custom handler in your application. The legacy `error_handler`
and `admin` options and `phpagi_error_handler()` function have been removed.

The FastAGI bootstrap is installed as `vendor/bin/fastagi.php` in consumer
applications. Configure your FastAGI service to execute that Composer binary so it
uses the application's autoloader. In this checkout, run
`php bin/fastagi.php` after `composer install`. The bootstrap expects the
connection on standard input and output; it is not a standalone TCP server.
The default script directory remains the package's `src/` directory; configure
`[fastagi] basedir` to use your application's AGI scripts directory.
Its existing path validation and configuration logging need attention before
exposing it to untrusted clients.

## Development and validation

```sh
composer validate --no-check-publish
composer test
```

The compatibility suite uses in-memory streams and requires no running Asterisk
server. It covers request initialization, response parsing, buffered digit helpers,
manager packets, event callbacks, manager creation, and preservation of the
application's PHP error handler and reporting level.
Live AGI, FastAGI, EAGI, and TTS integrations require separate integration testing.

Method parameters and return values now have native PHP types. Update application
subclasses to use compatible signatures when overriding library methods. Stream
parameters retain PHPDoc resource annotations because PHP has no native resource
type declaration.

PHP code uses tabs for indentation and short array syntax (`[]`). The indentation
setting is recorded in `.editorconfig`.

## Version 3.0.0

The source version is recorded in `VERSION` and the PHP file headers. Composer
derives the published package version from release tags.

This version sets PHP 8.1 as the minimum runtime, replaces removed string-offset
syntax, declares the manager property used internally, handles missing request
headers and stream EOF, and uses short array syntax throughout the PHP code.
Public AGI and AMI command methods are retained. The unused `AST_DIGIT_ANY` and
`AUDIO_FILENO` constants and the empty manager `log()` method have been removed.
The legacy global
`phpagi_error_handler()` function and its email diagnostics have been removed;
applications own PHP error handling. Composer autoloading is mandatory.

## License metadata

`composer.json` declares `GPL-2.0-or-later`, while the source headers state GNU
Lesser General Public License v2.1. These declarations conflict and should be
reconciled with the project's licensing history before publishing a release.
