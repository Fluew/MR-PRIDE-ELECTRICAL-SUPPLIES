<?php

namespace App\Services\Import;

use App\Exceptions\WorkflowException;
use App\Models\ImportBatch;
use App\Services\MaterialList\ExtractionException;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * An import step failed because of the server, not because of the file. The message is written
 * for staff and is safe to show: it never contains the exception text or SQL, only a reference
 * such as "(database error 42S22, import #12)" that an administrator can find in the logs.
 *
 * Stages: upload (the upload could not be recorded), reading (the file could not be read),
 * saving (the preview could not be saved), importing (the reviewed rows could not be imported).
 */
class ImportFailure extends RuntimeException
{
    public const UPLOAD = 'upload';

    public const READING = 'reading';

    public const SAVING = 'saving';

    public const IMPORTING = 'importing';

    /** SQLSTATE codes of a missing column or table (MySQL/MariaDB/SQLite, PostgreSQL). */
    private const SCHEMA_CODES = ['42S22', '42S02', '42703', '42P01'];

    private const SCHEMA_TEXT = '/unknown column|no such column|no such table|doesn.?t exist|does not exist|base table or view not found|column not found|undefined column|undefined table/i';

    /** @param  ?string  $sqlState  SQLSTATE of the database error behind it, if any */
    public function __construct(string $message, public readonly string $stage, public readonly ?string $sqlState = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** A safe staff message for $e at the given stage. */
    public static function fromThrowable(Throwable $e, string $stage, ?ImportBatch $batch = null): self
    {
        if ($e instanceof self) {
            return $e;
        }
        // These messages are already written for staff.
        if ($e instanceof ExtractionException) {
            return new self($e->getMessage(), self::READING, null, $e);
        }
        if ($e instanceof WorkflowException) {
            return new self($e->getMessage(), $stage, null, $e);
        }

        $code = self::sqlStateOf($e);
        $database = self::isDatabaseError($e);
        $schema = $database && self::isSchemaError($e);

        $lead = match ($stage) {
            self::UPLOAD => 'The upload could not be recorded',
            self::SAVING => 'The file was read, but its preview could not be saved',
            self::IMPORTING => 'The import could not be saved',
            default => 'The file could not be read',
        };

        $cause = match (true) {
            $schema => ' because the database structure is out of date: an update (migration) has not been run on the server.',
            $database => ' because of a database problem.',
            default => ' because of an unexpected server problem.',
        };
        $result = match ($stage) {
            self::UPLOAD => 'Nothing was saved.',
            self::IMPORTING => $schema ? 'Nothing was imported; your reviewed rows are kept.' : 'Nothing was imported and your reviewed rows are kept, so you can try again.',
            default => 'Nothing was imported.',
        };
        $advice = match (true) {
            $schema => 'Ask an administrator to run "php artisan migrate", then try again.',
            $stage === self::IMPORTING => 'If it keeps failing, ask an administrator to check the server logs.',
            default => 'Try uploading the file again; if it keeps failing, ask an administrator to check the server logs.',
        };
        $message = "{$lead}{$cause} {$result} {$advice}";

        $ref = array_filter([$code !== null ? "database error {$code}" : null, $batch?->getKey() ? "import #{$batch->getKey()}" : null]);

        return new self($message.($ref ? ' ('.implode(', ', $ref).')' : ''), $stage, $code, $e);
    }

    /** The SQLSTATE of the first database error in the chain, e.g. "42S22". */
    public static function sqlStateOf(Throwable $e): ?string
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof self && $x->sqlState !== null) {
                return $x->sqlState;
            }
            if (! $x instanceof QueryException && ! $x instanceof PDOException) {
                continue;
            }
            $info = $x->errorInfo ?? null;
            if (is_array($info) && is_string($info[0] ?? null) && preg_match('/^[0-9A-Z]{5}$/', $info[0]) && $info[0] !== '00000') {
                return $info[0];
            }
            $code = $x->getCode();
            if (is_string($code) && preg_match('/^[0-9A-Z]{5}$/', $code)) {
                return $code;
            }
            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $x->getMessage(), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    public static function isDatabaseError(Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof QueryException || $x instanceof PDOException || ($x instanceof self && $x->sqlState !== null)) {
                return true;
            }
        }

        return false;
    }

    /** A missing column or table: the database is older than the code (a migration was not run). */
    public static function isSchemaError(Throwable $e): bool
    {
        if (in_array(self::sqlStateOf($e), self::SCHEMA_CODES, true)) {
            return true;
        }
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if (($x instanceof QueryException || $x instanceof PDOException) && preg_match(self::SCHEMA_TEXT, $x->getMessage())) {
                return true;
            }
        }

        return false;
    }
}
