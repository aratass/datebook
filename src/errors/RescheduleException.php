<?php

namespace zemis\datebook\errors;

use yii\base\UserException;

/**
 * Thrown when an item cannot be moved to the requested date.
 * The message is safe to show to the user.
 */
class RescheduleException extends UserException
{
}
