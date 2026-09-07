# Contributing

Thanks for considering a contribution.

## Reporting a bug

Open an issue with the template. What matters most: your queue driver, your
`APEX_STORE`, the output of `php artisan apex:config:show`, and what you
expected instead. A scaling decision that looks wrong is almost never wrong in
isolation — it depends on depth, activity and the throttle state at that moment.

Never report a security issue as a public issue. See [SECURITY.md](SECURITY.md).

## Or skip the issue and open a pull request

A rough pull request is worth more here than a well-written issue, and you do
not have to write it yourself — describe the problem to a coding agent and let
it work in the repository. Even when the diff is wrong, it points at a place in
the code, which is most of the distance covered.

Often it will not get that far, and that is the useful part: an agent that
tries to write the fix tends to find that the queue driver was not what you
thought, that `apex:config:show` disagrees with the config you were reading, or
that the behaviour is deliberate and documented. That is triage you did not
have to wait for.

Point it at `AGENTS.md` before it touches the master loop. That file exists for
exactly this: it records the invariants that make an obvious-looking change
wrong, and without it an agent will confidently rediscover a bug we already
paid for. `composer test` has to pass before you open the PR.

Say in the PR what you are unsure about, and what the agent got wrong if you
already know. Confident prose over a wrong diff costs more review time than
"this fails on my setup, here is a failing test, the fix is probably nonsense".

## Working on the code

```bash
composer install
composer test      # Pest
composer analyse   # PHPStan level 8
composer lint      # Pint
```

All three have to pass. CI runs them across PHP 8.3–8.5 and Laravel 12–13, with
`--prefer-lowest` as well, which is what catches a version constraint that is
too loose.

To try a change against a real application, use the playground in the
[symphoria-packages](https://github.com/symphoria-io) working directory, or point any
Laravel app at your checkout with a path repository:

```bash
composer config repositories.apex path ../laravel-apex
composer require symphoria/laravel-apex:@dev
```

## What gets merged

**Tests are not optional.** A bugfix comes with the test that fails without it.
Anything touching scaling, throttling or the store belongs in `tests/Feature`
with the real store, not a mock — the two implementations are checked against
one shared contract test, and a change to one has to keep passing for the other.

This is the bar for merging, not for opening: a pull request that only proves
the problem is welcome, and the fix can be worked out in review.

**Do not add to `phpstan-baseline.neon`.** It holds findings inherited from the
code this package was extracted from, and the number only goes down. New code
passes level 8 on its own.

**Read `AGENTS.md` before changing the master loop.** It lists the decisions
that look arbitrary and are not: why bulk reads exist, why scale-down is
debounced, why one owner decides a worker's death. Most of them were paid for
in production.

**Keep the public API honest.** Contracts, config keys, table names, route names
and documented commands follow semver. Everything else is `@internal` and may
change. If your change breaks something public, say so in the PR — that is a
major, and better to know before the merge than after.

## Style

Pint handles formatting; run `composer lint` and do not argue with it.

Comments explain *why*, only where the code cannot. If the comment restates the
next line, drop it. English everywhere, including commit messages and test names.
