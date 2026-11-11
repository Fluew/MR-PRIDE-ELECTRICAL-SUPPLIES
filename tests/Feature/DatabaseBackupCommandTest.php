<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * db:backup runs mysqldump without a shell (Windows has no `| gzip`), passes the password in
 * MYSQL_PWD rather than on the command line, and gzips the dump in PHP.
 */

beforeEach(function () {
    $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-'.bin2hex(random_bytes(5));
    File::ensureDirectoryExists($this->storage);
    $this->app->useStoragePath($this->storage);
    config([
        'database.connections.mysql.database' => 'shop',
        'database.connections.mysql.username' => 'root',
        'database.connections.mysql.password' => 's3cret',
        'database.connections.mysql.mysqldump_path' => 'C:/xampp/mysql/bin/mysqldump.exe',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->storage));

it('dumps with the configured mysqldump, keeps the password off the command line and gzips the result', function () {
    $ran = null;
    Process::fake(function (PendingProcess $process) use (&$ran) {
        $ran = $process;
        $resultFile = substr(collect($process->command)->first(fn ($a) => str_starts_with($a, '--result-file=')), 14);
        file_put_contents($resultFile, "CREATE TABLE `users` (id int);\n-- Dump completed\n");

        return Process::result();
    });

    $this->artisan('db:backup')->assertSuccessful();

    expect($ran->command[0])->toBe('C:/xampp/mysql/bin/mysqldump.exe')
        ->and($ran->command)->toContain('--single-transaction', 'shop')
        ->and(implode(' ', $ran->command))->not->toContain('s3cret')
        ->and($ran->environment)->toMatchArray(['MYSQL_PWD' => 's3cret']);

    $files = glob($this->storage.'/app/backups/backup_shop_*');
    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('.sql.gz')
        ->and(gzdecode(file_get_contents($files[0])))->toContain('CREATE TABLE `users`');
});

it('fails clearly and leaves no file when mysqldump fails', function () {
    Process::fake(fn () => Process::result('', "mysqldump: Got error: 1045: Access denied for user 'root'", 2));

    $this->artisan('db:backup')->expectsOutputToContain('Access denied')->assertFailed();

    expect(glob($this->storage.'/app/backups/*'))->toBe([]);
});
