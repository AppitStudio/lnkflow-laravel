<?php

declare(strict_types=1);

use LnkFlow\Laravel\Tests\SandboxedTestCase;
use LnkFlow\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// `lnkflow:install` writes real files. These tests get an application in a
// throwaway directory so that publishing cannot disturb any other test.
uses(SandboxedTestCase::class)->in('Install');
