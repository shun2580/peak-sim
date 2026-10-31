<?php

declare(strict_types=1);

namespace PeakSim\Application;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\ActionReturnHook;
use PeakSim\Analysis\Handlers\Collection\CollectionHandlers;
use PeakSim\Analysis\Handlers\Query\BuilderOps;
use PeakSim\Analysis\Handlers\Query\ModelStatic;
use PeakSim\Analysis\Handlers\Query\RelationOps;

/**
 * The one place that decides which call handlers exist and in which order they are asked. A smaller number is
 * asked first. The principle (design 2.4): what changes the meaning of a call comes before the generic readers,
 * and the allow-list of known calls comes last, because "known" means "touches no database and calls nothing
 * back", which is a claim that must never shadow a handler that knows more.
 *
 *   10  ViewHandler            view() and Blade                       (PBl2)  not wired yet
 *   20  ResourceHandler, ResponseSink  resources, $appends, json()    (PR)    not wired yet
 *   30  CacheOps               Cache::remember and friends            (PK)    not wired yet
 *   40  Query                  models, builders, relations            (PQ)
 *   50  Collection             lists, array functions, proxies        (PC)
 *   90  KnownCalls             the allow-list                         (PK)    not wired yet
 *
 * Wiring a handler that exists later is one line in register() (and one in returnHook() for the sink).
 */
final class HandlerWiring
{
    public const PRIORITY_VIEW = 10;
    public const PRIORITY_RESPONSE = 20;
    public const PRIORITY_CACHE = 30;
    public const PRIORITY_QUERY = 40;
    public const PRIORITY_COLLECTION = 50;
    public const PRIORITY_KNOWN = 90;

    public static function registry(): HandlerRegistry
    {
        $registry = new HandlerRegistry();
        self::register($registry);

        return $registry;
    }

    public static function register(HandlerRegistry $registry): void
    {
        // PRIORITY_VIEW: ViewHandler (PBl2) is not committed yet.
        // PRIORITY_RESPONSE: ResourceHandler and ResponseSink (PR) are not committed yet.
        // PRIORITY_CACHE: CacheOps (PK) is not committed yet.

        $ops = new BuilderOps();
        $registry->register(new ModelStatic($ops), self::PRIORITY_QUERY);
        $registry->register($ops, self::PRIORITY_QUERY);
        $registry->register(new RelationOps($ops), self::PRIORITY_QUERY);

        CollectionHandlers::register($registry, self::PRIORITY_COLLECTION);

        // PRIORITY_KNOWN: KnownCalls (PK) is not committed yet.
    }

    /** The serialisation point at the end of an action (PR's ResponseSink). Null until PR is committed. */
    public static function returnHook(): ?ActionReturnHook
    {
        return null;
    }

    /**
     * What is not wired yet, for the reports and for anyone who wonders why a route is unknown.
     *
     * @return list<string>
     */
    public static function unwired(): array
    {
        return ['ViewHandler (PBl2)', 'ResourceHandler and ResponseSink (PR)', 'CacheOps (PK)', 'KnownCalls (PK)'];
    }
}
