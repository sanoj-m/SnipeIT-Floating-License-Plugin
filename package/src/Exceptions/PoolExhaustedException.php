<?php

namespace SnipeIt\FloatingLicenses\Exceptions;

use RuntimeException;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;

class PoolExhaustedException extends RuntimeException
{
    public function __construct(public readonly FloatingLicenseConfig $config)
    {
        parent::__construct('The floating license pool is exhausted and over-allocation is not allowed.');
    }
}
