<?php

namespace Symphoria\Apex\Master;

interface Clock
{
    public function now(): float;
}
