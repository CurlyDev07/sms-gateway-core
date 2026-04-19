<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        $this->guardAgainstUnsafeTestingDatabase();
        parent::setUp();
    }

    /**
     * Prevent tests from running against runtime databases.
     *
     * @return void
     */
    protected function guardAgainstUnsafeTestingDatabase(): void
    {
        $appEnv = strtolower($this->readEnv('APP_ENV', ''));

        if ($appEnv !== 'testing') {
            throw new RuntimeException(
                'Unsafe test configuration: APP_ENV must be "testing".'
            );
        }

        $connection = strtolower($this->readEnv('DB_CONNECTION', 'mysql'));
        $database = $this->readEnv('DB_DATABASE', '');
        $databaseLower = strtolower($database);

        if ($connection === 'sqlite') {
            if ($database === ':memory:' || str_contains($databaseLower, 'test')) {
                return;
            }

            throw new RuntimeException(
                'Unsafe sqlite test configuration: DB_DATABASE must be ":memory:" or contain "test".'
            );
        }

        if ($databaseLower === 'sms_gateway_core') {
            throw new RuntimeException(
                'Unsafe test database blocked: DB_DATABASE=sms_gateway_core. Use an isolated test database.'
            );
        }

        if (!str_contains($databaseLower, 'test')) {
            throw new RuntimeException(
                'Unsafe test configuration: DB_DATABASE must contain "test" for non-sqlite test runs.'
            );
        }
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    protected function readEnv(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $_SERVER) && is_scalar($_SERVER[$key])) {
            return trim((string) $_SERVER[$key]);
        }

        if (array_key_exists($key, $_ENV) && is_scalar($_ENV[$key])) {
            return trim((string) $_ENV[$key]);
        }

        $value = getenv($key);
        if (is_string($value)) {
            return trim($value);
        }

        return $default;
    }
}
