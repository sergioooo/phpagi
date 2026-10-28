<?php

error_reporting(E_ALL);
$applicationErrorHandler = function ($level, $message, $file, $line) {
	if (error_reporting() & $level)
	{
		throw new ErrorException($message, 0, $level, $file, $line);
	}
	return false;
};
set_error_handler($applicationErrorHandler);
$applicationErrorReporting = error_reporting();

require_once __DIR__ . '/../vendor/autoload.php';

function check($condition, $message)
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
}

function input_stream($contents)
{
	$stream = fopen('php://memory', 'r+');
	fwrite($stream, $contents);
	rewind($stream);
	return $stream;
}

function agi_response($agi, $response)
{
	$agi->in = input_stream($response);
	$agi->out = fopen('php://memory', 'r+');
	return $agi->answer();
}

// AMI must initialize before AGI is loaded; shared constants are eager-loaded.
check(!class_exists('AGI', false), 'AGI was eagerly loaded');
check(defined('DEFAULT_PHPAGI_CONFIG') && defined('AGIRES_OK'), 'Shared constants are unavailable');
$standaloneManager = new AGI_AsteriskManager();
check($standaloneManager->config['asmanager']['port'] === 5038, 'Standalone manager initialization failed');
check(!class_exists('AGI', false), 'Manager initialization eagerly loaded AGI');

// Initialization must tolerate missing optional request headers and EOF.
$agi = new AGI(null, ['tempdir' => sys_get_temp_dir()], input_stream("agi_callerid: 123\nmalformed header\n\n"));
check($agi->request === ['agi_callerid' => '123'], 'Request parsing failed');
$empty = new AGI(null, ['tempdir' => sys_get_temp_dir()], input_stream(''));
check($empty->request === [], 'Empty request must terminate at EOF');
check($empty->parse_callerid()['username'] === '', 'Empty caller ID failed');
check($agi->parse_callerid('"Caller" <sip:123@example.com:5060>') === [
	'name' => 'Caller', 'protocol' => 'sip', 'username' => '123', 'host' => 'example.com', 'port' => '5060',
], 'Caller ID parsing failed');

$result = agi_response($agi, "\n200 result=0 (answer accepted)\n");
check($result['code'] == 200 && $result['result'] === '0' && $result['data'] === 'answer accepted', 'AGI response parsing failed');
check(agi_response($agi, '')['result'] === -1, 'AGI EOF must return an error');
check(agi_response($agi, "520-Invalid command\nusage\n")['result'] === -1, 'Truncated multiline response must return an error');
check(agi_response($agi, "520-Invalid command\nusage\n520 End\n")['code'] === '520', 'Multiline response parsing failed');
check(agi_response($agi, "200\n")['data'] === '', 'Empty response payload failed');

// Enum-backed channel descriptions must preserve the AGI response contract.
$channelStates = [
	[AGI_ChannelState::DOWN, 0, 'Channel is down and available'],
	[AGI_ChannelState::RESERVED, 1, 'Channel is down, but reserved'],
	[AGI_ChannelState::OFFHOOK, 2, 'Channel is off hook'],
	[AGI_ChannelState::DIALING, 3, 'Digits (or equivalent) have been dialed'],
	[AGI_ChannelState::RING, 4, 'Line is ringing'],
	[AGI_ChannelState::RINGING, 5, 'Remote end is ringing'],
	[AGI_ChannelState::UP, 6, 'Line is up'],
	[AGI_ChannelState::BUSY, 7, 'Line is busy'],
	[AGI_ChannelState::DIALING_OFFHOOK, 8, 'Digits (or equivalent) have been dialed while offhook'],
	[AGI_ChannelState::PRERING, 9, 'Channel has detected an incoming call and is waiting for ring'],
];
foreach ($channelStates as [$state, $value, $description])
{
	check($state->value === $value, 'Channel state protocol value changed');
	$agi->in = input_stream("200 result=$value\n");
	$agi->out = fopen('php://memory', 'r+');
	check($agi->channel_status() === ['code' => '200', 'result' => (string) $value, 'data' => $description], 'Channel state response changed');
}
foreach ([-1 => 'There is no channel that matches test', 42 => 'Unknown (42)'] as $value => $description)
{
	$agi->in = input_stream("200 result=$value\n");
	$agi->out = fopen('php://memory', 'r+');
	check($agi->channel_status('test')['data'] === $description, 'Channel state fallback changed');
}

$buffer = '5';
foreach (['fastpass_say_digits', 'fastpass_say_number', 'fastpass_say_phonetic', 'fastpass_stream_file', 'fastpass_text2wav', 'fastpass_swift', 'fastpass_say_punctuation'] as $method)
{
	check($agi->$method($buffer, 'example', '5')['result'] === ord('5'), $method . ' failed');
}
check($agi->fastpass_say_time($buffer, null, '5')['result'] === ord('5'), 'Fastpass time failed');
$manager = $agi->new_AsteriskManager();
check($manager->pagi === $agi && $manager->config['phpagi']['tempdir'] === sys_get_temp_dir(), 'Manager creation failed');
$agi->config['phpagi']['debug'] = true;
check($manager->config['phpagi']['debug'] === true, 'Manager configuration reference was lost');
$manager->config['phpagi']['debug'] = false;
check($agi->config['phpagi']['debug'] === false, 'AGI configuration reference was lost');

// Manager packets and callbacks run without an Asterisk server.
$events = [];
$manager->add_event_handler('Test', function ($event, $parameters) use (&$events) {
	$events[] = $event;
	return $parameters['Value'];
});
check($manager->process_event(['Event' => 'Test', 'Value' => 'ok']) === 'ok', 'Closure event handler failed');
check($manager->process_event(['Event' => 'Unknown']) === false, 'Missing event handler failed');
foreach (['wait_response', 'wait_single_response'] as $method)
{
	$manager->socket = input_stream("Response: Success\r\nMessage: Done\r\n\r\n");
	check($manager->$method()['Message'] === 'Done', $method . ' failed');
	$manager->socket = input_stream('');
	check($manager->$method() === [], $method . ' must terminate at EOF');
	$manager->socket = input_stream("Response: Follows\r\noutput\r\n");
	check($manager->$method()['data'] === "output\r\n", $method . ' failed on truncated Follows packet');
}
$manager->socket = input_stream("Event: Test\r\nValue: event\r\n\r\nResponse: Success\r\n\r\n");
check($manager->wait_response()['Response'] === 'Success', 'Event before response failed');
check(count($events) === 2, 'Event dispatch failed');
$manager->disconnect();
$manager->disconnect();
check($manager->send_request('Ping') === [], 'Disconnected manager request failed');

// Originate must work without application-specific helper classes.
class CapturingManager extends AGI_AsteriskManager
{
	public function send_request(string $action, array $parameters = [], bool $waitSingleResponse = false): array
	{
		return $parameters;
	}
}
$capture = new CapturingManager();
$originate = $capture->Originate('SIP/test', variable: ['FIRST=1', 'SECOND=2']);
check($originate['Variable'] === 'FIRST=1|SECOND=2', 'Originate variable serialization failed');
$receiver = new class {
	public function handle($event, $parameters)
	{
		return $parameters['Value'];
	}
};
$manager->add_event_handler('Object', [$receiver, 'handle']);
check($manager->process_event(['Event' => 'Object', 'Value' => 'object']) === 'object', 'Object callback failed');

// Typed signatures must retain optional arguments, leading zeros, and mixed returns.
class RecordingAGI extends AGI
{
	public string $lastCommand = '';

	public function evaluate(string $command): array
	{
		$this->lastCommand = $command;
		return ['code' => '200', 'result' => '1', 'data' => 'value'];
	}
}
$recording = new RecordingAGI(null, ['tempdir' => sys_get_temp_dir()], input_stream(''));
$recording->say_digits('0012');
check($recording->lastCommand === 'SAY DIGITS 0012 ""', 'Typed digit input lost leading zeros');
$recording->set_var('VALUE', 1.5);
check($recording->lastCommand === 'Set(VALUE=1.5);', 'Numeric variable value changed');
check($recording->get_variable('VALUE')['data'] === 'value', 'Variable response array changed');
check($recording->get_variable('VALUE', true) === 'value', 'Variable value-only return changed');
check($recording->get_fullvariable('VALUE', false, true) === 'value', 'Optional full-variable channel changed');
$recording->record_file('recording', 'wav', beep: true, silence: 2);
check($recording->lastCommand === 'RECORD FILE recording wav "" -1 BEEP s=2', 'Optional recording arguments changed');
check($recording->text2wav('') === true && $recording->swift('') === true, 'Empty TTS return changed');
check($recording->say_punctuation('') === true, 'Empty punctuation return changed');
$recording->verbose(['message' => 'test']);
check(str_starts_with($recording->lastCommand, 'VERBOSE '), 'Mixed verbose input failed');
$originate = $capture->Originate('SIP/test', priority: 1, async: false, channelId: 'first', otherChannelId: 'second');
check($originate['Priority'] === 1 && $originate['Async'] === 'false', 'Originate optional scalar arguments changed');
check($originate['ChannelId'] === 'first' && $originate['OtherChannelId'] === 'second', 'Originate channel IDs changed');
$manager->add_event_handler('Mixed', static function () { return ['handled' => true]; });
check($manager->process_event(['Event' => 'Mixed']) === ['handled' => true], 'Mixed callback result changed');

// The application owns PHP error handling before and after library use.
$currentErrorHandler = set_error_handler($applicationErrorHandler);
check($currentErrorHandler === $applicationErrorHandler, 'Library replaced the application error handler');
restore_error_handler();
check(error_reporting() === $applicationErrorReporting, 'Library changed PHP error reporting');
$handled = false;
try
{
	trigger_error('Application error handling check', E_USER_WARNING);
}
catch (ErrorException $error)
{
	$handled = $error->getSeverity() === E_USER_WARNING;
}
check($handled, 'Application error handler did not receive the PHP warning');
restore_error_handler();
echo "PHP compatibility checks passed.\n";
