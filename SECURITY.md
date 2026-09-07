# Security Policy

## Supported versions

The latest minor release receives security fixes. While the package is on `0.x`,
that means the newest `0.x` tag.

## Reporting a vulnerability

**Do not open a public issue.** A public report tells everyone running the
package about the hole before there is a fix.

Use GitHub's private vulnerability reporting instead: go to the
[Security tab](https://github.com/symphoria-io/laravel-apex/security) and press
**Report a vulnerability**. That opens a thread only the maintainers can see,
and it is the whole channel — there is no separate mailbox to chase.

Worth including:

- what the issue is and what an attacker gains
- the affected version
- steps to reproduce, or a proof of concept

You are credited in the release notes unless you would rather not be.

## Scope

The control API is the part worth looking at hardest. It can pause queues and
flush failed jobs, which is why access is denied outside `local` unless the
application opens it explicitly through `Apex::auth()` or a `viewApex` gate.

Out of scope: an application that binds its own authorization callback to
something permissive, or that sets `apex.dashboard.enabled` while exposing the
routes without any gate. That is a configuration mistake in the host, not a
vulnerability in the package — though if the documentation led someone there,
tell us, because that is worth fixing too.
