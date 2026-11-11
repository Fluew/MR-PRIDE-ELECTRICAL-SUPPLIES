<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DatabaseBackup extends Command
{
    protected $signature = 'db:backup {--max-files=7 : Maximum backup files to keep}';

    protected $description = 'Create a compressed MySQL database backup';

    public function handle(): int
    {
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port', 3306);

        $backupDir = storage_path('app/backups');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $filename = "backup_{$database}_".date('Y-m-d_His').'.sql.gz';
        $filepath = "{$backupDir}/{$filename}";
        $sqlPath = substr($filepath, 0, -3);

        // No shell: works on Windows (no `| gzip`), the password goes in MYSQL_PWD instead of the
        // command line, and mysqldump's location is configurable (XAMPP does not put it on PATH).
        $result = Process::timeout(600)
            ->env($password !== '' && $password !== null ? ['MYSQL_PWD' => $password] : [])
            ->run([
                config('database.connections.mysql.mysqldump_path') ?: 'mysqldump',
                '--host='.$host,
                '--port='.$port,
                '--user='.$username,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--result-file='.$sqlPath,
                $database,
            ]);

        if (! $result->successful() || ! is_file($sqlPath) || filesize($sqlPath) === 0) {
            @unlink($sqlPath);
            $errorMsg = trim($result->errorOutput()."\n".$result->output()) ?: 'mysqldump produced no output.';
            Log::channel('security')->error("Database backup failed: {$errorMsg}");
            $this->error("Backup failed: {$errorMsg}");

            return self::FAILURE;
        }

        $this->compress($sqlPath, $filepath);

        $size = filesize($filepath);
        $sizeFormatted = number_format($size / 1024 / 1024, 2).' MB';

        Log::channel('security')->info("Database backup created: {$filename} ({$sizeFormatted})");
        $this->info("Backup created: {$filename} ({$sizeFormatted})");

        $this->pruneOldBackups($backupDir, (int) $this->option('max-files'));

        return self::SUCCESS;
    }

    /** Gzip the dump in PHP (streamed, so large dumps do not need to fit in memory), then remove the plain copy. */
    private function compress(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = gzopen($to, 'wb6');
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 512));
        }
        fclose($in);
        gzclose($out);
        unlink($from);
    }

    private function pruneOldBackups(string $dir, int $maxFiles): void
    {
        $files = glob("{$dir}/backup_*.sql.gz");
        if ($files === false || count($files) <= $maxFiles) {
            return;
        }

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        $toDelete = array_slice($files, $maxFiles);
        foreach ($toDelete as $file) {
            unlink($file);
            $this->line('Pruned old backup: '.basename($file));
        }
    }
}
