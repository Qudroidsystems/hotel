<?php

namespace App\Exceptions;

use RuntimeException;

/** A booking rule was broken (no availability, wrong status…). The message is shown to staff as-is. */
class BookingException extends RuntimeException
{
}
