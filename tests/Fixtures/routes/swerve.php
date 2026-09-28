<?php

require __DIR__ . '/vendor/autoload.php';

// KERNELS: the tests' way to try the serial mode (kernels: 1)
return new Swerve\Symfony\Handler(__DIR__, kernels: (int) (\getenv('KERNELS') ?: 16));
