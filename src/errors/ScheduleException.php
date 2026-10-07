<?php

namespace zemis\datebook\errors;

use yii\base\UserException;

/**
 * Thrown when a draft cannot be scheduled.
 * The message is safe to show to the user.
 */
class ScheduleException extends UserException
{
}
