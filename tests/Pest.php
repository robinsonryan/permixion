<?php

declare(strict_types=1);

use RobinsonRyan\Permixion\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Postgres', 'Unit');

/**
 * A well-formed RFC 9562 UUIDv7 — version nibble 7, variant bits 10.
 *
 * Asserting the shape is what separates "the database generated this" from "some
 * code path put a string here": a UUIDv4 from PHP fails the version nibble, and a
 * null key fails the whole pattern.
 */
expect()->extend('toBeUuidV7', function (): object {
    expect($this->value)
        ->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');

    return $this;
});
