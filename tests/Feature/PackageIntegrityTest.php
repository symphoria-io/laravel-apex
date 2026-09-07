<?php

declare(strict_types=1);

use Symphoria\Apex\Apex;

it('contains no closures, so config:cache keeps working', function (): void {
    $config = require __DIR__.'/../../config/apex.php';

    expect(fn (): string => var_export($config, true))->not->toThrow(Throwable::class);
});

it('only reads env() inside the config file', function (): void {
    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('/(?<![\w:>$])env\s*\(/', $contents) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBeEmpty();
});

it('carries no reference to the framework it was extracted from', function (): void {
    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'Wps\\Framework')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBeEmpty();
});

/**
 * The namespace check above missed `wps:bench:*` command signatures and a
 * Laragon-specific hint in the start command. Scan the text too, not just the
 * imports.
 *
 * A bare `symphoria` cannot be listed here since the rename: it is the vendor
 * namespace of this package. `wps` still catches the host application it was
 * extracted from, including its Symphoria module.
 */
it('mentions no host application or local toolchain', function (): void {
    $forbidden = ['wps', 'laragon', 'symphoria_pings'];
    $offenders = [];

    foreach (['src', 'config', 'routes'] as $dir) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../'.$dir)) as $file) {
            if (! in_array($file->getExtension(), ['php', 'neon'], true)) {
                continue;
            }

            $contents = strtolower((string) file_get_contents($file->getPathname()));

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $file->getFilename().' → '.$needle;
                }
            }
        }
    }

    expect($offenders)->toBeEmpty();
});

it('merges the package config', function (): void {
    expect(config('apex.table_names.queue_states'))->toBe('apex_queue_states')
        ->and(config('apex.store_keys.control_suspended_prefix'))->toBe('apex:control:suspended:');
});

it('creates the store tables regardless of which store is active', function (): void {
    // Making a migration conditional on config gives you a schema that
    // differs per environment, and turns changing APEX_STORE into a deploy.
    expect(Schema::hasTable(Apex::table('store')))->toBeTrue()
        ->and(Schema::hasTable(Apex::table('timeline')))->toBeTrue();
});

it('registers the artisan commands', function (): void {
    $commands = array_keys(app('Illuminate\Contracts\Console\Kernel')->all());

    expect($commands)->toContain('apex:start')
        ->and($commands)->toContain('apex:reconcile')
        ->and($commands)->toContain('apex:work');
});

it('reports itself with a version', function (): void {
    expect(Apex::version())->toBeString()->not->toBeEmpty();
});
