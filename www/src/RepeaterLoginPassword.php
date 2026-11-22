<?php

namespace MeshCore;

final class RepeaterLoginPassword
{
    // MeshCore's companion firmware uses at most 15 bytes for repeater login.
    private const MAX_BYTES = 15;

    public static function normalize($value): string
    {
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException('Kartotuvo slaptažodis turi būti tekstas.');
        }

        $password = trim($value ?? '');
        if (strlen($password) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Kartotuvo slaptažodis negali būti ilgesnis nei ' . self::MAX_BYTES . ' baitų.');
        }

        return $password;
    }
}
