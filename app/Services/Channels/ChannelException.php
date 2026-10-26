<?php

namespace App\Services\Channels;

use RuntimeException;

/** Something went wrong talking to a channel (bad key, the site is down, it refused an update). */
class ChannelException extends RuntimeException
{
}
