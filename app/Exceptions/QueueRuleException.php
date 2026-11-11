<?php

namespace App\Exceptions;

use DomainException;

/** A queue business rule was violated. The message is written for the end user. */
class QueueRuleException extends DomainException {}
