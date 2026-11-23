#!/usr/bin/env python3
"""Select the routes of Bagisto v2.5.2 that the capture and the measurement run.

The rule was fixed before any capture of the prepared data was taken, and is not
changed after seeing a result. Standard library only; the output depends on
nothing but `routes-all.txt` and the seed, so it can be run again and compared.

    python3 -I select-routes.py routes-all.txt OUTPUT_DIR

Input  : routes-all.txt, one line per route the static analysis reports (543),
         "METHODS URI" with HEAD dropped and several methods joined by ",".
Output : excluded.tsv       the routes left out before the draw, one reason each
         allocation.tsv     how many routes each HTTP method gets in each group
         route-set-measure.txt  the routes drawn, "METHODS URI", sorted by group then URI
         drawn.tsv          the same with group and method

Steps (fixed):
1. Leave out, before the draw, only: the development-package routes, /install,
   the payment-gateway return routes (the page the gateway sends the buyer back to:
   success, cancel, failure, fail, callback), the forms of the login/register/contact
   kind, and the pages that show an empty state. Nothing else is left out. In
   particular the gateway's redirect, create-order, capture-order, webhook and IPN
   routes are not return routes and stay in the population.
2. Two groups: the admin area (URI is /admin or starts with /admin/) and the
   storefront and customer side (all the rest). The slots are 80 and 40.
3. A group with no more routes than its slots is taken whole. The unused slots
   are not given to the other group.
4. Otherwise the slots are shared among the HTTP methods in proportion to how
   many routes each method has in the group (largest remainder: each method gets
   floor(slots * n_method / n_group); the slots still free go one each to the
   methods with the largest fractional part, ties to the method with more routes,
   then to the method name in alphabetical order). A method never gets more than it has.
5. In each method, the routes are put in the order of sha256("<seed>:<METHODS>:<URI>")
   and the first k are taken. The seed is 20261124. The order does not depend on the
   Python version or on the order of the input.
"""
import hashlib
import sys
from collections import defaultdict

SEED = 20261124
SLOTS = {"storefront": 80, "admin": 40}

PAYMENT_PREFIXES = ("payglocal", "paypal", "payu", "phonepe", "razorpay", "stripe")
# The last path segment of a route the payment gateway sends the buyer back to.
PAYMENT_RETURN_SEGMENTS = {"success", "cancel", "failure", "fail", "callback"}

FORM_URIS = {
    "/customer/login", "/api/customer/login", "/customer/register",
    "/contact-us", "/contact-us/send-mail",
    "/customer/forgot-password", "/customer/reset-password", "/customer/reset-password/{token}",
    "/admin/login", "/admin/forget-password", "/admin/reset-password", "/admin/reset-password/{token}",
}

EMPTY_STATE_GET_URIS = {
    "/checkout/cart", "/checkout/onepage", "/checkout/onepage/success", "/compare",
    "/api/checkout/cart", "/api/checkout/cart/cross-sell", "/api/checkout/onepage/summary",
    "/api/compare-items",
}


def exclusion_reason(methods, uri):
    """Return the reason this route is left out before the draw, or None."""
    first = uri.strip("/").split("/", 1)[0]
    if uri == "/_debugbar" or uri.startswith("/_debugbar/"):
        return "development package route (debugbar)"
    if uri == "/install" or uri.startswith("/install/"):
        return "installer route"
    if first in PAYMENT_PREFIXES and uri.rstrip("/").rsplit("/", 1)[-1] in PAYMENT_RETURN_SEGMENTS:
        return "payment gateway return route (" + first + ")"
    if uri in FORM_URIS:
        return "form of the login, register or contact kind"
    if methods == "GET" and uri in EMPTY_STATE_GET_URIS:
        return "page or call that shows the visitor's own cart, compare list or checkout step, empty for a new visitor"
    return None


def group_of(uri):
    return "admin" if (uri == "/admin" or uri.startswith("/admin/")) else "storefront"


def rank_key(methods, uri):
    return hashlib.sha256(f"{SEED}:{methods}:{uri}".encode()).hexdigest()


def allocate(counts, slots):
    """Largest-remainder shares of `slots` over the methods in `counts` (dict method -> n)."""
    total = sum(counts.values())
    base = {m: (slots * n) // total for m, n in counts.items()}
    rest = slots - sum(base.values())
    order = sorted(counts, key=lambda m: (-((slots * counts[m]) % total), -counts[m], m))
    for m in order[:rest]:
        base[m] += 1
    assert all(base[m] <= counts[m] for m in counts)
    assert sum(base.values()) == slots
    return base


def main(argv):
    src, out = argv[1], argv[2]
    routes = []
    for line in open(src, encoding="utf-8"):
        line = line.rstrip("\n")
        if not line:
            continue
        methods, uri = line.split(" ", 1)
        routes.append((methods, uri))
    assert len(set(routes)) == len(routes)

    excluded, groups = [], defaultdict(lambda: defaultdict(list))
    for methods, uri in routes:
        why = exclusion_reason(methods, uri)
        if why:
            excluded.append((methods, uri, why))
        else:
            groups[group_of(uri)][methods].append(uri)

    alloc_rows, drawn = [], []
    for g in ("storefront", "admin"):
        by_method = groups[g]
        counts = {m: len(u) for m, u in by_method.items()}
        n = sum(counts.values())
        slots = SLOTS[g]
        if n <= slots:
            take = dict(counts)
            how = "whole group (no more routes than slots)"
        else:
            take = allocate(counts, slots)
            how = "proportional by method"
        for m in sorted(counts):
            alloc_rows.append((g, m, counts[m], take[m], how))
            uris = sorted(by_method[m], key=lambda u: rank_key(m, u))[: take[m]]
            drawn.extend((g, m, u) for u in uris)

    excluded.sort(key=lambda r: (r[1], r[0]))
    with open(f"{out}/excluded.tsv", "w", encoding="utf-8") as f:
        f.write("methods\turi\treason\n")
        for methods, uri, why in excluded:
            f.write(f"{methods}\t{uri}\t{why}\n")
    with open(f"{out}/allocation.tsv", "w", encoding="utf-8") as f:
        f.write("group\tmethods\troutes_in_group\tdrawn\thow\n")
        for row in alloc_rows:
            f.write("\t".join(map(str, row)) + "\n")
    drawn.sort(key=lambda r: (r[0] != "storefront", r[2], r[1]))
    with open(f"{out}/drawn.tsv", "w", encoding="utf-8") as f:
        f.write("group\tmethods\turi\n")
        for g, m, u in drawn:
            f.write(f"{g}\t{m}\t{u}\n")
    with open(f"{out}/route-set-measure.txt", "w", encoding="utf-8") as f:
        for g, m, u in drawn:
            f.write(f"{m} {u}\n")

    print(f"routes in the analysis: {len(routes)}")
    print(f"left out before the draw: {len(excluded)}")
    for g in ("storefront", "admin"):
        n = sum(len(u) for u in groups[g].values())
        k = sum(1 for d in drawn if d[0] == g)
        print(f"{g}: {n} routes, {SLOTS[g]} slots, drawn {k}")
    print(f"drawn in all: {len(drawn)}")


if __name__ == "__main__":
    main(sys.argv)
