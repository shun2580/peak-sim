# r-resource-plaint-property-gap

**Purpose.** Guards for the D21 rule around the declared-property fix (cmd_248 FIX3): a property read on a value that is
not an object the tool knows (a column value, a property with no declared type) is a gap and makes the axes unknown; it is
never passed through silently. A call the tool cannot follow (a vendor object held in a promoted property) stays a gap too.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`): `GET /<name>` → `TestController@<camelName>` for each name in `routes/web.php`.

## Files to write

The standard files, three resources (`app/Http/Resources/`) and `app/Http/Controllers/TestController.php`. Every planted line carries a `// PLANT:` comment.

## What the tool must say (`expect.php`)

- `/untyped-prop`: `$this->order` has no declared type, so `->customer` is read on something unknown: gap `UNRESOLVED_CALL`, both axes unknown.
- `/vendor-prop`: a method call on an unmodelled vendor object: a gap, both axes unknown.
- `/scalar-chain` and `/scalar-chain-plain`: `->amount` is read on a column value: gap `UNRESOLVED_CALL`, both axes unknown (the plain one has no resource at all).
