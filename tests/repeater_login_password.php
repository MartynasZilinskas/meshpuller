<?php

require __DIR__ . '/../www/src/autoload.php';

use MeshCore\RepeaterLoginPassword;

foreach ([
    [null, ''],
    ['   ', ''],
    [' ' . str_repeat('x', 15) . ' ', str_repeat('x', 15)],
    [str_repeat('é', 7) . 'x', str_repeat('é', 7) . 'x'],
] as [$input, $expected]) {
    if (RepeaterLoginPassword::normalize($input) !== $expected) {
        throw new RuntimeException('Valid repeater password was changed or rejected.');
    }
}

foreach ([str_repeat('x', 16), str_repeat('é', 8), 123, ['x']] as $invalid) {
    try {
        RepeaterLoginPassword::normalize($invalid);
    } catch (InvalidArgumentException $e) {
        continue;
    }
    throw new RuntimeException('Invalid repeater password was accepted.');
}

echo "Repeater login password checks passed.\n";
