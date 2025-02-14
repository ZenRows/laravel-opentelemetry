<?php

namespace LaravelOpenTelemetry\Instrumentation;

interface Instrumentation
{
    public function register(array $options): void;
}
