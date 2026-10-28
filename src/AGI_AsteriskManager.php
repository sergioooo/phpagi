<?php
/**
 * AGI_AsteriskManager.php : PHP Asterisk Manager functions
 * Website: http://phpagi.sourceforge.net
 *
 * $Id: phpagi-asmanager.php,v 1.10 2005/05/25 18:43:48 pinhole Exp $
 *
 * Copyright (c) 2004 - 2010 Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
 * All Rights Reserved.
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 *  A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * We would be happy to list your phpagi based application on the phpagi
 * website.  Drop me an Email if you'd like us to list your program.
 *
 * @package phpAGI
 * @version 3.0.0
 */


/**
 * Requires PHP 8.1 or later.
 *
 */

/**
 * Asterisk Manager class
 *
 * @link http://www.voip-info.org/wiki-Asterisk+config+manager.conf
 * @link http://www.voip-info.org/wiki-Asterisk+manager+API
 * @example examples/sip_show_peer.php Get information about a sip peer
 * @package phpAGI
 */
class AGI_AsteriskManager
{
	public array $config = [];

	/** @var resource|false|null Manager connection. */
	public $socket = null;

	public ?string $server = null;

	// Explicit server ports and INI settings can supply numeric strings.
	public int|string|null $port = null;

	public ?AGI $pagi = null;

	private array $event_handlers = [];

	private bool $_logged_in = false;

	/**
	* Constructor
	*
	* @param ?string $config name of the configuration file to parse
	* @param array $optconfig is an array of configuration vars and vals, stuffed into $this->config['asmanager']
	*/
	public function __construct(?string $config = null, array $optconfig = [])
	{
		// load config
		if(!is_null($config) && file_exists($config))
			$this->config = parse_ini_file($config, true) ?: [];
		elseif(file_exists(DEFAULT_PHPAGI_CONFIG))
			$this->config = parse_ini_file(DEFAULT_PHPAGI_CONFIG, true) ?: [];

		// If optconfig is specified, stuff vals and vars into 'asmanager' config array.
		foreach($optconfig as $var=>$val)
			$this->config['asmanager'][$var] = $val;

		// add default values to config for uninitialized values
		if(!isset($this->config['asmanager']['server'])) $this->config['asmanager']['server'] = 'localhost';
		if(!isset($this->config['asmanager']['port'])) $this->config['asmanager']['port'] = 5038;
		if(!isset($this->config['asmanager']['username'])) $this->config['asmanager']['username'] = 'phpagi';
		if(!isset($this->config['asmanager']['secret'])) $this->config['asmanager']['secret'] = 'phpagi';
	}

	/**
	* Send a request
	*
	* @return array of parameters
	* @author sergio - added $waitSingleResponse to prevent blocking indefinitely
	*/
	public function send_request(string $action, array $parameters = [], bool $waitSingleResponse = false): array
	{
		if(!is_resource($this->socket)) return [];

		$req = "Action: $action\r\n";
		foreach($parameters as $var=>$val)
			$req .= "$var: $val\r\n";
		$req .= "\r\n";
		if(fwrite($this->socket, $req) === false) return [];
		return $waitSingleResponse ? $this->wait_single_response() : $this->wait_response();
	}

	/**
	* Wait for a response
	*
	* If a request was just sent, this will return the response.
	* Otherwise, it will loop forever, handling events.
	*
	* @param bool $allow_timeout if the socket times out, return an empty array
	* @return array of parameters, empty on timeout
	*/
	public function wait_response(bool $allow_timeout = false): array
	{
		if(!is_resource($this->socket)) return [];
		$timeout = false;
		do
		{
			$type = NULL;
			$parameters = [];

			$line = fgets($this->socket, 4096);
			if($line === false) return $parameters;
			$buffer = trim($line);
			while($buffer != '')
			{
				$a = strpos($buffer, ':');
				if($a)
				{
					if($parameters === []) // first line in a response?
					{
						$type = strtolower(substr($buffer, 0, $a));
						if(substr($buffer, $a + 2) == 'Follows')
						{
							// A follows response means there is a multiline field that follows.
							$parameters['data'] = '';
							$buff = fgets($this->socket, 4096);
							while($buff !== false && substr($buff, 0, 6) != '--END ')
							{
								$parameters['data'] .= $buff;
								$buff = fgets($this->socket, 4096);
							}
						}
					}

					// store parameter in $parameters
					$parameters[substr($buffer, 0, $a)] = substr($buffer, $a + 2);
				}
				$line = fgets($this->socket, 4096);
				if($line === false) return $parameters;
				$buffer = trim($line);
			}

			// process response
			switch($type)
			{
				case '': // timeout occurred
					$timeout = $allow_timeout;
					break;
				case 'event':
					$this->process_event($parameters);
					break;
				case 'response':
					break;
				default:
					break;
			}
		} while($type != 'response' && !$timeout);
		return $parameters;
	}


	/**
	 * Wait for a single response then return.
	 * Reads one packet, dispatches an event if present, and returns.
	 * @author sergio
	 *
	 * @return array
	 */
	public function wait_single_response(): array
	{
		if(!is_resource($this->socket)) return [];
		$type = NULL;
		$parameters = [];

		$line = fgets($this->socket, 4096);
		if($line === false) return $parameters;
		$buffer = trim($line);
		while($buffer != '')
		{
			$a = strpos($buffer, ':');
			if($a)
			{
				if($parameters === []) // first line in a response?
				{
					$type = strtolower(substr($buffer, 0, $a));
					if(substr($buffer, $a + 2) == 'Follows')
					{
						// A follows response means there is a multiline field that follows.
						$parameters['data'] = '';
						$buff = fgets($this->socket, 4096);
						while($buff !== false && substr($buff, 0, 6) != '--END ')
						{
							$parameters['data'] .= $buff;
							$buff = fgets($this->socket, 4096);
						}
					}
				}

				// store parameter in $parameters
				$parameters[substr($buffer, 0, $a)] = substr($buffer, $a + 2);
			}
			$line = fgets($this->socket, 4096);
			if($line === false) return $parameters;
			$buffer = trim($line);
		}

		// process response
		switch($type)
		{
			case '': // timeout occurred
				break;
			case 'event':
				$this->process_event($parameters);
				break;
			case 'response':
				break;
			default:
				break;
		}
		return $parameters;
	}

	/**
	* Connect to Asterisk
	*
	* @example examples/sip_show_peer.php Get information about a sip peer
	*
	* @return bool true on success
	*/
	public function connect(?string $server = null, ?string $username = null, ?string $secret = null): bool
	{
		// use config if not specified
		if(is_null($server)) $server = $this->config['asmanager']['server'];
		if(is_null($username)) $username = $this->config['asmanager']['username'];
		if(is_null($secret)) $secret = $this->config['asmanager']['secret'];

		// get port from server if specified
		if(strpos($server, ':') !== false)
		{
			$c = explode(':', $server);
			$this->server = $c[0];
			$this->port = $c[1];
		}
		else
		{
			$this->server = $server;
			$this->port = $this->config['asmanager']['port'];
		}

		// connect the socket
		$this->socket = @fsockopen($this->server, $this->port);
		if($this->socket == false)
		{
			return false;
		}

		// read the header
		$str = fgets($this->socket);
		if($str == false)
		{
			// a problem.
			return false;
		}

		// login
		$res = $this->send_request('login', ['Username'=>$username, 'Secret'=>$secret]);
		if(($res['Response'] ?? '') != 'Success')
		{
			$this->_logged_in = FALSE;
			$this->disconnect();
			return false;
		}
		$this->_logged_in = TRUE;
		return true;
	}

	/**
	* Disconnect
	*
	* @example examples/sip_show_peer.php Get information about a sip peer
	*/
	public function disconnect(): void
	{
		if($this->_logged_in==TRUE)
			$this->logoff();
		if(is_resource($this->socket)) fclose($this->socket);
		$this->socket = NULL;
		$this->_logged_in = FALSE;
	}

	// *********************************************************************************************************
	// **                       COMMANDS                                                                      **
	// *********************************************************************************************************

	/**
	* Set Absolute Timeout
	*
	* Hangup a channel after a certain time.
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+AbsoluteTimeout
	* @param string $channel Channel name to hangup
	* @param int $timeout Maximum duration of the call (sec)
	*/
	public function AbsoluteTimeout(string $channel, int $timeout): array
	{
		return $this->send_request('AbsoluteTimeout', ['Channel'=>$channel, 'Timeout'=>$timeout]);
	}

	/**
	* Change monitoring filename of a channel
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ChangeMonitor
	* @param string $channel the channel to record.
	* @param string $file the new name of the file created in the monitor spool directory.
	*/
	public function ChangeMonitor(string $channel, string $file): array
	{
		return $this->send_request('ChangeMontior', ['Channel'=>$channel, 'File'=>$file]);
	}

	/**
	* Execute Command
	*
	* @example examples/sip_show_peer.php Get information about a sip peer
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Command
	* @link http://www.voip-info.org/wiki-Asterisk+CLI
	* @param ?string $actionid message matching variable
	*/
	public function Command(string $command, ?string $actionid = null): array
	{
		$parameters = ['Command'=>$command];
		if($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('Command', $parameters);
	}

	/**
	* Enable/Disable sending of events to this manager
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Events
	* @param string $eventmask is either 'on', 'off', or 'system,call,log'
	*/
	public function Events(string $eventmask): array
	{
		return $this->send_request('Events', ['EventMask'=>$eventmask]);
	}

	/**
	* Check Extension Status
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ExtensionState
	* @param string $exten Extension to check state on
	* @param string $context Context for extension
	* @param ?string $actionid message matching variable
	*/
	public function ExtensionState(string $exten, string $context, ?string $actionid = null): array
	{
		$parameters = ['Exten'=>$exten, 'Context'=>$context];
		if($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('ExtensionState', $parameters);
	}

	/**
	* Gets a Channel Variable
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+GetVar
	* @link http://www.voip-info.org/wiki-Asterisk+variables
	* @param string $channel Channel to read variable from
	* @param ?string $actionid message matching variable
	*/
	public function GetVar(string $channel, string $variable, ?string $actionid = null): array
	{
		$parameters = ['Channel'=>$channel, 'Variable'=>$variable];
		if($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('GetVar', $parameters);
	}

	/**
	* Hangup Channel
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Hangup
	* @param string $channel The channel name to be hungup
	*/
	public function Hangup(string $channel): array
	{
		return $this->send_request('Hangup', ['Channel'=>$channel]);
	}

	/**
	* List IAX Peers
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+IAXpeers
	*/
	public function IAXPeers(): array
	{
		return $this->send_request('IAXPeers');
	}

	/**
	* List available manager commands
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ListCommands
	* @param ?string $actionid message matching variable
	*/
	public function ListCommands(?string $actionid = null): array
	{
		if($actionid)
			return $this->send_request('ListCommands', ['ActionID'=>$actionid]);
		else
			return $this->send_request('ListCommands');
	}

	/**
	* Logoff Manager
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Logoff
	*/
	public function Logoff(): array
	{
		return $this->send_request('Logoff');
	}

	/**
	* Check Mailbox Message Count
	*
	* Returns number of new and old messages.
	*   Message: Mailbox Message Count
	*   Mailbox: <mailboxid>
	*   NewMessages: <count>
	*   OldMessages: <count>
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxCount
	* @param string $mailbox Full mailbox ID <mailbox>@<vm-context>
	* @param ?string $actionid message matching variable
	*/
	public function MailboxCount(string $mailbox, ?string $actionid = null): array
	{
		$parameters = ['Mailbox'=>$mailbox];
		if($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('MailboxCount', $parameters);
	}

	/**
	* Check Mailbox
	*
	* Returns number of messages.
	*   Message: Mailbox Status
	*   Mailbox: <mailboxid>
	*   Waiting: <count>
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxStatus
	* @param string $mailbox Full mailbox ID <mailbox>@<vm-context>
	* @param ?string $actionid message matching variable
	*/
	public function MailboxStatus(string $mailbox, ?string $actionid = null): array
	{
		$parameters = ['Mailbox'=>$mailbox];
		if($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('MailboxStatus', $parameters);
	}

	/**
	* Monitor a channel
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Monitor
	*/
	public function Monitor(string $channel, ?string $file = null, ?string $format = null, ?bool $mix = null): array
	{
		$parameters = ['Channel'=>$channel];
		if($file) $parameters['File'] = $file;
		if($format) $parameters['Format'] = $format;
		if(!is_null($file)) $parameters['Mix'] = ($mix) ? 'true' : 'false';
		return $this->send_request('Monitor', $parameters);
	}

	/**
	* Originate Call
	*
	* @link https://wiki.asterisk.org/wiki/display/AST/Asterisk+18+ManagerAction_Originate
	* @param string $channel Channel name to call
	* @param ?string $exten Extension to use (requires 'Context' and 'Priority')
	* @param ?string $context Context to use (requires 'Exten' and 'Priority')
	* @param int|string|null $priority Priority to use (requires 'Exten' and 'Context')
	* @param ?string $application Application to use
	* @param ?string $data Data to use (requires 'Application')
	* @param ?int $timeout How long to wait for call to be answered (in ms)
	* @param ?string $callerid Caller ID to be set on the outgoing channel
	* @param string|array|null $variable Channel variables (VAR1=value1|VAR2=value2 or an array of entries)
	* @param ?string $account Account code
	* @param ?bool $async true fast origination
	* @param ?string $actionid message matching variable
	* sergio: Added variable implode and other/channelId spec
	*/
	public function Originate(
		string $channel,
		?string $exten = null,
		?string $context = null,
		int|string|null $priority = null,
		?string $application = null,
		?string $data = null,
		?int $timeout = null,
		?string $callerid = null,
		string|array|null $variable = null,
		?string $account = null,
		?bool $async = null,
		?string $actionid = null,
		?string $channelId = null,
		?string $otherChannelId = null
	): array
	{
		$parameters = ['Channel'=>$channel];

		if($exten) $parameters['Exten'] = $exten;
		if($context) $parameters['Context'] = $context;
		if($priority) $parameters['Priority'] = $priority;

		if($application) $parameters['Application'] = $application;
		if($data) $parameters['Data'] = $data;

		if($timeout) $parameters['Timeout'] = $timeout;
		if($callerid) $parameters['CallerID'] = $callerid;
		if($variable) $parameters['Variable'] = is_array($variable) ? implode('|', $variable) : $variable;
		if($account) $parameters['Account'] = $account;
		if(!is_null($async)) $parameters['Async'] = ($async) ? 'true' : 'false';
		if($actionid) $parameters['ActionID'] = $actionid;

		if($channelId) $parameters['ChannelId'] = $channelId;
		if($otherChannelId) $parameters['OtherChannelId'] = $otherChannelId;

		return $this->send_request('Originate', $parameters);
	}


	/**
	* List parked calls
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ParkedCalls
	* @param ?string $actionid message matching variable
	*/
	public function ParkedCalls(?string $actionid = null): array
	{
		if($actionid)
			return $this->send_request('ParkedCalls', ['ActionID'=>$actionid]);
		else
			return $this->send_request('ParkedCalls');
	}

	/**
	* Ping
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Ping
	*/
	public function Ping(): array
	{
		return $this->send_request('Ping');
	}

	/**
	* Queue Add
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueAdd
	*/
	public function QueueAdd(string $queue, string $interface, int $penalty = 0): array
	{
		$parameters = ['Queue'=>$queue, 'Interface'=>$interface];
		if($penalty) $parameters['Penalty'] = $penalty;
		return $this->send_request('QueueAdd', $parameters);
	}

	/**
	* Queue Remove
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueRemove
	*/
	public function QueueRemove(string $queue, string $interface): array
	{
		return $this->send_request('QueueRemove', ['Queue'=>$queue, 'Interface'=>$interface]);
	}

	/**
	* Queues
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Queues
	*/
	public function Queues(): array
	{
		return $this->send_request('Queues');
	}

	/**
	* Queue Status
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueStatus
	* @param ?string $actionid message matching variable
	*/
	public function QueueStatus(?string $actionid = null): array
	{
		if($actionid)
			return $this->send_request('QueueStatus', ['ActionID'=>$actionid]);
		else
			return $this->send_request('QueueStatus');
	}

	/**
	* Redirect
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Redirect
	*/
	public function Redirect(string $channel, string $extrachannel, string $exten, string $context, int|string $priority): array
	{
		return $this->send_request('Redirect', ['Channel'=>$channel, 'ExtraChannel'=>$extrachannel, 'Exten'=>$exten,
			'Context'=>$context, 'Priority'=>$priority]);
	}

	/**
	* Set the CDR UserField
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetCDRUserField
	*/
	public function SetCDRUserField(string $userfield, string $channel, string|bool|null $append = null): array
	{
		$parameters = ['UserField'=>$userfield, 'Channel'=>$channel];
		if($append) $parameters['Append'] = $append;
		return $this->send_request('SetCDRUserField', $parameters);
	}

	/**
	* Set Channel Variable
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetVar
	* @param string $channel Channel to set variable for
	* @param string $variable name
	*/
	public function SetVar(string $channel, string $variable, string $value): array
	{
		return $this->send_request('SetVar', ['Channel'=>$channel, 'Variable'=>$variable, 'Value'=>$value]);
	}

	/**
	* Channel Status
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Status
	* @param ?string $actionid message matching variable
	*/
	public function Status(string $channel, ?string $actionid = null): array
	{
		$parameters = ['Channel'=>$channel];
		if($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('Status', $parameters);
	}

	/**
	* Stop monitoring a channel
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+StopMonitor
	*/
	public function StopMonitor(string $channel): array
	{
		return $this->send_request('StopMonitor', ['Channel'=>$channel]);
	}

	/**
	* Dial over Zap channel while offhook
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDialOffhook
	*/
	public function ZapDialOffhook(string $zapchannel, string $number): array
	{
		return $this->send_request('ZapDialOffhook', ['ZapChannel'=>$zapchannel, 'Number'=>$number]);
	}

	/**
	* Toggle Zap channel Do Not Disturb status OFF
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDoff
	*/
	public function ZapDNDoff(string $zapchannel): array
	{
		return $this->send_request('ZapDNDoff', ['ZapChannel'=>$zapchannel]);
	}

	/**
	* Toggle Zap channel Do Not Disturb status ON
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDon
	*/
	public function ZapDNDon(string $zapchannel): array
	{
		return $this->send_request('ZapDNDon', ['ZapChannel'=>$zapchannel]);
	}

	/**
	* Hangup Zap Channel
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapHangup
	*/
	public function ZapHangup(string $zapchannel): array
	{
		return $this->send_request('ZapHangup', ['ZapChannel'=>$zapchannel]);
	}

	/**
	* Transfer Zap Channel
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapTransfer
	*/
	public function ZapTransfer(string $zapchannel): array
	{
		return $this->send_request('ZapTransfer', ['ZapChannel'=>$zapchannel]);
	}

	/**
	* Zap Show Channels
	*
	* @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapShowChannels
	* @param ?string $actionid message matching variable
	*/
	public function ZapShowChannels(?string $actionid = null): array
	{
		if($actionid)
			return $this->send_request('ZapShowChannels', ['ActionID'=>$actionid]);
		else
			return $this->send_request('ZapShowChannels');
	}

	// *********************************************************************************************************
	// **                       MISC                                                                          **
	// *********************************************************************************************************

	/**
	* Add event handler
	*
	* Known Events include ( http://www.voip-info.org/wiki-asterisk+manager+events )
	*   Link - Fired when two voice channels are linked together and voice data exchange commences.
	*   Unlink - Fired when a link between two voice channels is discontinued, for example, just before call completion.
	*   Newexten -
	*   Hangup -
	*   Newchannel -
	*   Newstate -
	*   Reload - Fired when the "RELOAD" console command is executed.
	*   Shutdown -
	*   ExtensionStatus -
	*   Rename -
	*   Newcallerid -
	*   Alarm -
	*   AlarmClear -
	*   Agentcallbacklogoff -
	*   Agentcallbacklogin -
	*   Agentlogoff -
	*   MeetmeJoin -
	*   MessageWaiting -
	*   join -
	*   leave -
	*   AgentCalled -
	*   ParkedCall - Fired after ParkedCalls
	*   Cdr -
	*   ParkedCallsComplete -
	*   QueueParams -
	*   QueueMember -
	*   QueueStatusEnd -
	*   Status -
	*   StatusComplete -
	*   ZapShowChannels - Fired after ZapShowChannels
	*   ZapShowChannelsComplete -
	*
	* @param string $event type or * for default handler
	* @param callable $callback function
	* @return bool sucess
	*/
	public function add_event_handler(string $event, callable $callback): bool
	{
		$event = strtolower($event);
		if(isset($this->event_handlers[$event]))
		{
			return false;
		}
		$this->event_handlers[$event] = $callback;
		return true;
	}

	/**
	* Process event
	*
	* @return mixed result of event handler or false if no handler was found
	*/
	public function process_event(array $parameters): mixed
	{
		$ret = false;
		$e = strtolower($parameters['Event']);

		$handler = '';
		if(isset($this->event_handlers[$e])) $handler = $this->event_handlers[$e];
		elseif(isset($this->event_handlers['*'])) $handler = $this->event_handlers['*'];

		if(is_callable($handler))
		{
			$ret = $handler($e, $parameters, $this->server, $this->port);
		}
		return $ret;
	}

}
?>
