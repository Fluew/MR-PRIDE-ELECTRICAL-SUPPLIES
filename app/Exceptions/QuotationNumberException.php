<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * No unique quotation number could be assigned (every attempt collided with an existing number).
 * The message is for the logs; show users a plain "please try again" instead.
 */
class QuotationNumberException extends RuntimeException {}
