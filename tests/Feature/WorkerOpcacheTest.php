<?php

declare(strict_types=1);

use Symphoria\Apex\Process\ProcessFactory;

function workerPhpFlags(): array
{
    return (new ReflectionMethod(ProcessFactory::class, 'buildPhpFlags'))->invoke(new ProcessFactory);
}

it('spawns workers without OPcache flags by default', function () {
    expect(config('apex.worker.opcache.enabled'))->toBeFalse()
        ->and(workerPhpFlags())->toBe([]);
});

it('treats a missing enabled key as disabled', function () {
    config(['apex.worker.opcache' => []]);

    expect(workerPhpFlags())->toBe([]);
});

it('injects OPcache flags when explicitly enabled', function () {
    $cacheDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'apex-opcache-'.getmypid().'-'.uniqid();
    config([
        'apex.worker.opcache.enabled' => true,
        'apex.worker.opcache.file_cache_dir' => $cacheDir,
    ]);

    try {
        expect(workerPhpFlags())->toContain('opcache.enable_cli=1', 'opcache.file_cache='.$cacheDir);
    } finally {
        @rmdir($cacheDir);
    }
});
