<?php

namespace TelescopeMonitor\Tests\Fixtures;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

// Legacy hosts may supply a dual-key reader through Laravel's encrypter binding.
final class RotatingEncrypter extends Encrypter
{
    public function __construct(string $key, string $cipher, private Encrypter $previous)
    {
        parent::__construct($key, $cipher);
    }

    public function decrypt($payload, $unserialize = true)
    {
        try {
            return parent::decrypt($payload, $unserialize);
        } catch (DecryptException) {
            return $this->previous->decrypt($payload, $unserialize);
        }
    }
}
