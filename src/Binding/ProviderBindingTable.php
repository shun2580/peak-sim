<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;
use PeakSim\Source\ClassInfo;
use PeakSim\Source\ParseFailed;
use PeakSim\Source\SourceIndex;

/**
 * The container bindings of an app, read from its service providers without booting anything.
 *
 * resolve() returns every implementation an abstract can have. It never decides which binding wins:
 * the environment is unknown, so a later binding does not replace an earlier one, and an
 * environment name is never evaluated.
 *
 * An Unresolvable result carries a reason that starts with REASON_DECORATED (the abstract is wrapped
 * by extend() or afterResolving(): BINDING_DECORATED) or REASON_UNRESOLVABLE (anything that could not
 * be read, or that may hide a binding: UNRESOLVABLE_BINDING).
 */
final class ProviderBindingTable implements BindingTable
{
    public const REASON_DECORATED = 'decorated:';
    public const REASON_UNRESOLVABLE = 'unresolvable:';

    private ?BindingFacts $facts = null;

    public function __construct(private readonly SourceIndex $source)
    {
    }

    public function resolve(string $abstract, ?string $consumer = null): BindingResult
    {
        $abstract = ltrim($abstract, '\\');
        $consumer = $consumer === null ? null : ltrim($consumer, '\\');
        try {
            $facts = $this->facts ??= $this->load();
            $info = $this->source->findClass($abstract);
        } catch (ParseFailed $e) {
            return BindingResult::unresolvable(new Site(ProviderLocator::fileOf($e, 'bootstrap/providers.php'), 1), self::REASON_UNRESOLVABLE . ' ' . $e->getMessage());
        }

        $decorated = $facts->decoration($abstract);
        if ($decorated !== null) {
            return BindingResult::unresolvable($decorated[0], $decorated[1]);
        }
        $unreadable = $facts->firstUnreadable();
        if ($unreadable !== null) {
            return BindingResult::unresolvable($unreadable[0], $unreadable[1]);
        }

        $entries = $facts->entriesFor($abstract);
        $general = $this->general($entries, $info);
        if ($consumer === null) {
            return $general;
        }
        $contextual = array_values(array_filter(
            $entries,
            static fn (BindingEntry $e): bool => $e->consumer === $consumer || $e->consumer === BindingEntry::ANY_CONSUMER,
        ));
        if ($contextual === []) {
            return $general;
        }
        foreach ($contextual as $entry) {
            if (!$entry->isReadable()) {
                return BindingResult::unresolvable($entry->failedAt ?? new Site('', 0), (string) $entry->failure);
            }
        }
        $candidates = array_merge(...array_map(static fn (BindingEntry $e): array => $e->candidates, $contextual));
        if (array_filter($candidates, static fn (Candidate $c): bool => !$c->conditional) !== []) {
            return BindingResult::candidates($candidates);
        }
        // every contextual binding is conditional: the plain binding may apply instead
        if ($general->kind === BindingResultKind::Unresolvable) {
            return $general;
        }

        return BindingResult::candidates(array_merge($candidates, $general->candidates));
    }

    /**
     * The plain (non-contextual) answer.
     *
     * @param list<BindingEntry> $entries every entry for the abstract
     */
    private function general(array $entries, ?ClassInfo $info): BindingResult
    {
        $plain = array_values(array_filter($entries, static fn (BindingEntry $e): bool => $e->consumer === null));
        foreach ($plain as $entry) {
            if (!$entry->isReadable()) {
                return BindingResult::unresolvable($entry->failedAt ?? new Site('', 0), (string) $entry->failure);
            }
        }
        $candidates = $plain === [] ? [] : array_merge(...array_map(static fn (BindingEntry $e): array => $e->candidates, $plain));
        $concrete = $info !== null && $info->kind === 'class' && !$info->isAbstract;
        if (!$concrete) {
            return $candidates === [] ? BindingResult::none() : BindingResult::candidates($candidates);
        }
        $unconditional = array_filter($candidates, static fn (Candidate $c): bool => !$c->conditional) !== [];
        if (!$unconditional) {
            $candidates[] = new Candidate($info->fqcn, new Site($info->file, $info->node->getStartLine()), $candidates !== [], 'self');
        }

        return BindingResult::candidates($candidates);
    }

    private function load(): BindingFacts
    {
        $facts = new BindingFacts();
        [$providers, $problems] = (new ProviderLocator($this->source))->locate();
        foreach ($problems as [$site, $reason]) {
            $facts->unreadable($site, self::REASON_UNRESOLVABLE . ' ' . $reason);
        }
        $scanner = new ProviderScanner($this->source, $facts);
        foreach ($providers as $provider) {
            try {
                $scanner->scanProvider($provider);
            } catch (ParseFailed $e) {
                $facts->unreadable(new Site(ProviderLocator::fileOf($e, 'bootstrap/providers.php'), 1), self::REASON_UNRESOLVABLE . ' ' . $e->getMessage());
            }
        }

        return $facts;
    }
}
