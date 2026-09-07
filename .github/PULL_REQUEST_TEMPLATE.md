## What this changes

<!-- And why. Link the issue if there is one. -->

## Checklist

- [ ] `composer test`, `composer analyse` and `composer lint` all pass
- [ ] A bugfix comes with the test that fails without it
- [ ] Nothing added to `phpstan-baseline.neon`
- [ ] No unbatched per-queue, per-worker or per-dispatch work in the master loop
- [ ] `CHANGELOG.md` updated under Unreleased
- [ ] Public API unchanged, or the break is described above
