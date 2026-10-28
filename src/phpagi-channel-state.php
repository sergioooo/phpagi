<?php

/**
 * Asterisk channel states returned by CHANNEL STATUS.
 *
 * @package phpAGI
 * @version 3.0.0
 */
enum AGI_ChannelState: int
{
	case DOWN = 0;
	case RESERVED = 1;
	case OFFHOOK = 2;
	case DIALING = 3;
	case RING = 4;
	case RINGING = 5;
	case UP = 6;
	case BUSY = 7;
	case DIALING_OFFHOOK = 8;
	case PRERING = 9;
}
