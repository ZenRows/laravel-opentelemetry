<?php

namespace LaravelOpenTelemetry\Tests\Support;

class TestEvent
{
    public function __construct(public string $value) {}
}
