<?php

declare(strict_types=1);

use Symphoria\Apex\Tests\TestCase;

// Only Feature: tests/Unit holds plain PHPUnit classes that must not boot an app.
uses(TestCase::class)->in('Feature');
