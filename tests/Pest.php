<?php

/*
 * Datebook test suite.
 *
 * The tests run inside a Craft project (see ci/project and ci/run-tests.sh).
 * Craft is booted and installed by markhuot/craft-pest-core, and every feature
 * test runs in a database transaction that is rolled back afterwards.
 *
 * Shared fixtures (plugin install, sites, sections) are created by
 * Fixtures::boot(), which every test file calls in a file level beforeAll().
 * That hook runs before the first test of the file and outside the per-test
 * transactions, so the fixtures stay in the database. Creating them is
 * idempotent, so the second file finds them already in place.
 */

use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;

require_once __DIR__ . '/Support/Fixtures.php';
require_once __DIR__ . '/Support/OtherJob.php';
require_once __DIR__ . '/Support/OutputCapture.php';
require_once __DIR__ . '/Support/SqsLikeQueue.php';

uses(TestCase::class, RefreshesDatabase::class)
    // Queue rows are rolled back after each test, but the cache is not. Start every
    // test without a remembered publish job, or no job would be pushed.
    ->beforeEach(fn() => \zemis\datebook\Datebook::getInstance()?->schedules->forgetQueuedJobs())
    ->in('Feature');
uses(TestCase::class)->in('Unit');
