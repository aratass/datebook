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
require_once __DIR__ . '/Support/OutputCapture.php';

uses(TestCase::class, RefreshesDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');
