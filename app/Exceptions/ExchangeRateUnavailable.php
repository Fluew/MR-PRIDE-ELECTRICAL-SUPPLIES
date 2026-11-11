<?php

namespace App\Exceptions;

use RuntimeException;

/** The exchange rate service failed or sent something unusable. The message is safe to show users. */
class ExchangeRateUnavailable extends RuntimeException {}
