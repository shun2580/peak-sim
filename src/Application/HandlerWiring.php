<?php

declare(strict_types=1);

namespace PeakSim\Application;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Blade\ViewHandler;
use PeakSim\Analysis\Handlers\Cache\CacheOps;
use PeakSim\Analysis\Handlers\Collection\CollectionHandlers;
use PeakSim\Analysis\Handlers\Known\KnownCalls;
use PeakSim\Analysis\Handlers\Query\BuilderOps;
use PeakSim\Analysis\Handlers\Query\ModelStatic;
use PeakSim\Analysis\Handlers\Query\RelationOps;
use PeakSim\Analysis\Serialization\Serialization;

/**
 * The one place that decides which call handlers exist and in which order they are asked. A smaller number is
 * asked first. The principle (design 2.4): what changes the meaning of a call comes before the generic readers,
 * and the allow-list of known calls comes last, because "known" means "touches no database and calls nothing
 * back", which is a claim that must never shadow a handler that knows more.
 *
 *   10  ViewHandler            view() and Blade                       (PBl2)
 *   20  ResourceHandler, ResponseSink  resources, $appends, json()    (PR)
 *   30  CacheOps               Cache::remember and friends            (PK)
 *   40  Query                  models, builders, relations            (PQ)
 *   50  Collection             lists, array functions, proxies        (PC)
 *   90  KnownCalls             the allow-list                         (PK)
 *
 * Wiring a handler is one line in register(). The sink is also the Engine's end-of-action hook; wire() returns it.
 */
final class HandlerWiring
{
    public const PRIORITY_VIEW = 10;
    public const PRIORITY_RESPONSE = 20;
    public const PRIORITY_CACHE = 30;
    public const PRIORITY_QUERY = 40;
    public const PRIORITY_COLLECTION = 50;
    public const PRIORITY_KNOWN = 90;

    /**
     * The registry and the end-of-action hook that go together (the sink is both a handler and the hook, and its
     * Serializer must be the one the resource handler uses).
     *
     * @param string $appRoot the app whose views the ViewHandler reads
     * @param string|null $viewPath a view directory that replaces config/view.php's paths
     */
    public static function wire(string $appRoot, ?string $viewPath = null): WiredHandlers
    {
        $registry = new HandlerRegistry();
        $serialization = self::register($registry, $appRoot, $viewPath);

        return new WiredHandlers($registry, $serialization->hook);
    }

    public static function register(HandlerRegistry $registry, string $appRoot, ?string $viewPath = null): Serialization
    {
        $registry->register(new ViewHandler($appRoot, $viewPath), self::PRIORITY_VIEW);

        $serialization = Serialization::create();
        foreach ($serialization->handlers as $handler) {
            $registry->register($handler, self::PRIORITY_RESPONSE);
        }

        $registry->register(new CacheOps(), self::PRIORITY_CACHE);

        $ops = new BuilderOps();
        $registry->register(new ModelStatic($ops), self::PRIORITY_QUERY);
        $registry->register($ops, self::PRIORITY_QUERY);
        $registry->register(new RelationOps($ops), self::PRIORITY_QUERY);

        CollectionHandlers::register($registry, self::PRIORITY_COLLECTION);

        $registry->register(new KnownCalls(), self::PRIORITY_KNOWN);

        return $serialization;
    }
}
