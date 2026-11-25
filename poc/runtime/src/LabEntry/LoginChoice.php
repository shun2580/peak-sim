<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabEntry;

use PeakSim\Runtime\Run\ActingAs;

/**
 * Who the lab's entry script logs in as for one request (poc/lab/README.md, "The login of a request").
 *
 * The BookStack lab has always had two answers: the one user named by PEAK_LAB_ACT_AS, or nobody when the request carries
 * `X-Lab-Login: none`. That stays exactly so while no list of logins is offered. An app whose routes need more than one login
 * (Bagisto: a storefront customer, and an administrator on another guard) names them in PEAK_LAB_LOGINS, and a request picks one
 * by its name in the same header.
 *
 * PEAK_LAB_LOGINS is `name=Model\Class:key:guard`, entries separated by `;`. A request can only choose among the names listed
 * here; it can never name a user model or a key of its own. When a list is offered, a name that is not on it is a refusal
 * (never a fall back to the default login, which would measure another request than the one the capture saw).
 */
final class LoginChoice
{
    /** The header value that asks for no login at all. */
    public const GUEST = 'none';

    /**
     * @return array<string, string> name => `Model\Class:key:guard`, in the order written
     *
     * @throws \InvalidArgumentException an entry that is not `name=spec`, a name used twice, the reserved name, or a spec ActingAs cannot read
     */
    public static function parseOffered(string $text): array
    {
        $offered = [];
        foreach (explode(';', $text) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^(?<name>[a-z][a-z0-9_-]{0,31})=(?<spec>\S+)$/D', $entry, $m) !== 1) {
                throw new \InvalidArgumentException('a login must look like name=Model\\Class:key:guard: ' . mb_substr($entry, 0, 80));
            }
            if ($m['name'] === self::GUEST) {
                throw new \InvalidArgumentException('the login name "' . self::GUEST . '" is the request with no login and cannot be offered');
            }
            if (isset($offered[$m['name']])) {
                throw new \InvalidArgumentException('the login name is listed twice: ' . $m['name']);
            }
            [, , $guard] = ActingAs::parse($m['spec']);
            if ($guard === null) {
                throw new \InvalidArgumentException('the login "' . $m['name'] . '" names no guard');
            }
            $offered[$m['name']] = $m['spec'];
        }

        return $offered;
    }

    /**
     * @param array<string, string> $offered the logins on offer (empty: only the default and the guest, as before)
     *
     * @return string `Model\Class:key[:guard]` to act as, or '' for a request with no login
     *
     * @throws \InvalidArgumentException a list is offered and the header names something else than `none` or a name on it
     */
    public static function resolve(string $header, string $default, array $offered): string
    {
        if ($header === self::GUEST) {
            return '';
        }
        if ($offered === [] || $header === '') {
            return $default;
        }
        if (!isset($offered[$header])) {
            throw new \InvalidArgumentException('the login is not one the lab offers: ' . mb_substr($header, 0, 40));
        }

        return $offered[$header];
    }

    /**
     * The name a spec has on the offered list, or null when it is not on it.
     *
     * @param array<string, string> $offered
     */
    public static function nameOf(string $spec, array $offered): ?string
    {
        $name = array_search($spec, $offered, true);

        return $name === false ? null : (string) $name;
    }
}
