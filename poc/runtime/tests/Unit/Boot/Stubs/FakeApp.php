<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Boot\Stubs;

/** The few things of a started app that the boot steps use: config, bound instances, events and the database. */
final class FakeApp implements \ArrayAccess
{
    /** @var array<string, mixed> */
    public array $services = [];

    /** @var list<string> every service asked for, in order */
    public array $asked = [];

    /** @var array<string, object> */
    public array $instances = [];

    public function __construct(array $config = [])
    {
        $this->services['config'] = new FakeConfig($config);
        $this->services['events'] = new FakeEvents();
        $this->services['db'] = new FakeDb();
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->services[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->asked[] = (string) $offset;

        return $this->services[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->services[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->services[$offset]);
    }

    public function instance(string $abstract, object $instance): object
    {
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    public function make(string $abstract): mixed
    {
        return $this->instances[$abstract] ?? $this->services[$abstract] ?? null;
    }

    public function config(): FakeConfig
    {
        return $this->services['config'];
    }
}

final class FakeConfig
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
}

final class FakeEvents
{
    /** @var array<string, list<callable>> */
    public array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function fire(string $event, object $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }
}

final class FakeDb
{
    public int $level = 0;

    public function connection(): self
    {
        return $this;
    }

    public function transactionLevel(): int
    {
        return $this->level;
    }
}
