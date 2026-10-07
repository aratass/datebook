<?php

namespace zemis\datebook\tests\Support;

use php_user_filter;

/**
 * Stream filter that swallows what is written to STDOUT and keeps it in memory,
 * so tests can check console output without printing it.
 */
final class OutputCapture extends php_user_filter
{
    public const FILTER = 'datebook.capture';

    public static string $buffer = '';

    public static function register(): void
    {
        if (!in_array(self::FILTER, stream_get_filters(), true)) {
            stream_filter_register(self::FILTER, self::class);
        }
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$buffer .= $bucket->data;
            $consumed += $bucket->datalen;
            $bucket->data = '';
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
