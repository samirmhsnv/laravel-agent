<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Sql;

use SamirMhsnv\LaravelAgent\Exceptions\UnsafeQueryException;

/**
 * Decides whether a model-written SQL string is a single, read-only SELECT
 * over allowlisted tables.
 *
 * It is a conservative tokenizer, not a full SQL parser: when in doubt it
 * rejects. Rules:
 *
 *  - one statement only (a single trailing semicolon is tolerated)
 *  - must start with SELECT or WITH
 *  - no comments of any kind (--, #, slash-star), no backslashes in string literals
 *  - no write, DDL, transaction, session or file keywords anywhere outside string literals
 *  - no dangerous functions (sleep, file access, extension loading...)
 *  - every table after FROM / JOIN must be allowlisted (CTE names are fine)
 *  - no table-valued functions in FROM
 *
 * Treat this as one layer. The other layer, which you should also have, is a
 * database user that can only SELECT.
 */
final class SqlValidator
{
    /** Keywords that have no business in a read-only query. */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'UPSERT', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE', 'RENAME',
        'GRANT', 'REVOKE', 'ATTACH', 'DETACH', 'PRAGMA', 'VACUUM', 'REINDEX', 'CALL', 'EXEC', 'EXECUTE',
        'DO', 'HANDLER', 'LOAD', 'COPY', 'INTO', 'OUTFILE', 'DUMPFILE', 'LOCK', 'UNLOCK', 'SET', 'RESET',
        'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'PREPARE', 'DEALLOCATE', 'LISTEN', 'NOTIFY',
        'SHOW', 'DESCRIBE', 'EXPLAIN', 'USE', 'KILL', 'SHUTDOWN', 'FLUSH', 'INSTALL', 'UNINSTALL',
    ];

    /** Functions that sleep, touch the file system, or reach outside the database. */
    private const FORBIDDEN_FUNCTIONS = [
        'SLEEP', 'BENCHMARK', 'PG_SLEEP', 'PG_SLEEP_FOR', 'PG_SLEEP_UNTIL', 'LOAD_FILE', 'LOAD_EXTENSION',
        'READFILE', 'WRITEFILE', 'EDIT', 'FTS3_TOKENIZER', 'PG_READ_FILE', 'PG_READ_BINARY_FILE', 'PG_LS_DIR',
        'PG_STAT_FILE', 'LO_IMPORT', 'LO_EXPORT', 'LO_GET', 'LO_PUT', 'DBLINK', 'DBLINK_EXEC', 'QUERY_TO_XML',
        'PG_TERMINATE_BACKEND', 'PG_CANCEL_BACKEND', 'SET_CONFIG', 'CURRENT_SETTING', 'SYS_EXEC', 'SYS_EVAL',
        'XP_CMDSHELL', 'OPENROWSET', 'OPENDATASOURCE', 'GET_LOCK', 'RELEASE_LOCK', 'PG_ADVISORY_LOCK',
    ];

    /** Words that end a table reference (so they are not mistaken for an alias). */
    private const CLAUSE_WORDS = [
        'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'OFFSET', 'HAVING', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL',
        'OUTER', 'CROSS', 'NATURAL', 'ON', 'USING', 'UNION', 'INTERSECT', 'EXCEPT', 'WINDOW', 'FETCH', 'FOR',
        'AS', 'SELECT', 'FROM', 'STRAIGHT_JOIN', 'QUALIFY', 'TABLESAMPLE', 'WITH',
    ];

    /** @var array<string, true> */
    private array $allowed = [];

    /** @param  list<string>  $allowedTables */
    public function __construct(array $allowedTables, private readonly int $maxLength = 4000)
    {
        foreach ($allowedTables as $table) {
            $this->allowed[strtolower($table)] = true;
        }
    }

    /**
     * Validate and normalise the query.
     *
     * @return string The query without a trailing semicolon.
     *
     * @throws UnsafeQueryException
     */
    public function validate(string $sql): string
    {
        $sql = trim($sql);

        if ($sql === '') {
            throw UnsafeQueryException::because('the query is empty.');
        }

        if (strlen($sql) > $this->maxLength) {
            throw UnsafeQueryException::because("the query is longer than {$this->maxLength} characters.");
        }

        if (str_contains($sql, "\0")) {
            throw UnsafeQueryException::because('the query contains a null byte.');
        }

        $tokens = $this->tokenize($sql);

        // Allow exactly one trailing semicolon.
        if (end($tokens) === [';', ';']) {
            array_pop($tokens);
            $sql = rtrim(substr($sql, 0, strrpos($sql, ';')));
        }

        foreach ($tokens as [$type]) {
            if ($type === ';') {
                throw UnsafeQueryException::because('only a single statement is allowed.');
            }
        }

        if ($tokens === [] || ! in_array($this->word($tokens[0]), ['SELECT', 'WITH'], true)) {
            throw UnsafeQueryException::because('only SELECT queries are allowed.');
        }

        foreach ($tokens as $i => $token) {
            $word = $this->word($token);

            if ($word === null) {
                continue;
            }

            if (in_array($word, self::FORBIDDEN_KEYWORDS, true)) {
                throw UnsafeQueryException::because("the keyword {$word} is not allowed in a read-only query.");
            }

            if (in_array($word, self::FORBIDDEN_FUNCTIONS, true) && ($tokens[$i + 1][0] ?? null) === '(') {
                throw UnsafeQueryException::because("the function {$word}() is not allowed.");
            }
        }

        $this->checkTables($tokens);

        return $sql;
    }

    /**
     * Every table name referenced after FROM or JOIN, lower-cased.
     *
     * @return list<string>
     */
    public function tables(string $sql): array
    {
        return array_values(array_unique($this->tableReferences($this->tokenize(trim($sql)))));
    }

    /** @param  list<array{0: string, 1: string}>  $tokens */
    private function checkTables(array $tokens): void
    {
        $ctes = $this->cteNames($tokens);

        foreach ($this->tableReferences($tokens) as $table) {
            if (isset($ctes[$table])) {
                continue;
            }

            if (! isset($this->allowed[$table])) {
                $allowed = $this->allowed === [] ? 'none are configured' : 'allowed tables: '.implode(', ', array_keys($this->allowed));

                throw UnsafeQueryException::because("table [{$table}] is not allowed ({$allowed}).");
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return list<string>
     */
    private function tableReferences(array $tokens): array
    {
        $tables = [];
        $parens = []; // stack: true when the paren belongs to a function call
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$type] = $tokens[$i];

            if ($type === '(') {
                $previous = $i > 0 ? $tokens[$i - 1] : null;
                // A function call's parens, unless they wrap a subquery: ARRAY(SELECT ...) is still checked.
                $parens[] = $previous !== null
                    && $previous[0] === 'word'
                    && ! in_array($this->word($tokens[$i + 1] ?? null), ['SELECT', 'WITH'], true)
                    && ! in_array(strtoupper($previous[1]), ['FROM', 'JOIN', 'IN', 'EXISTS', 'AS', 'ON', 'LATERAL', 'SELECT', 'WHERE', 'AND', 'OR', 'NOT', 'ANY', 'ALL', 'SOME', 'UNION', 'USING'], true);

                continue;
            }

            if ($type === ')') {
                array_pop($parens);

                continue;
            }

            $word = $this->word($tokens[$i]);

            if ($word !== 'FROM' && $word !== 'JOIN') {
                continue;
            }

            // EXTRACT(YEAR FROM x), SUBSTRING(s FROM 2), TRIM(LEADING 'a' FROM s): not a table.
            if ($word === 'FROM' && end($parens) === true) {
                continue;
            }

            // a IS DISTINCT FROM b
            if ($word === 'FROM' && $i > 0 && $this->word($tokens[$i - 1]) === 'DISTINCT') {
                continue;
            }

            $j = $i + 1;

            while ($j < $count) {
                if ($this->word($tokens[$j]) === 'LATERAL' || $this->word($tokens[$j]) === 'ONLY') {
                    $j++;

                    continue;
                }

                if (($tokens[$j][0] ?? null) === '(') {
                    break; // subquery: its own FROM is checked when the loop reaches it
                }

                if (! in_array($tokens[$j][0] ?? null, ['word', 'ident'], true)) {
                    break;
                }

                $name = $tokens[$j][1];

                while (($tokens[$j + 1][0] ?? null) === '.' && in_array($tokens[$j + 2][0] ?? null, ['word', 'ident'], true)) {
                    $name .= '.'.$tokens[$j + 2][1];
                    $j += 2;
                }

                if (($tokens[$j + 1][0] ?? null) === '(') {
                    throw UnsafeQueryException::because("table-valued functions such as {$name}() are not allowed in FROM.");
                }

                $tables[] = strtolower($name);
                $j++;

                // Optional alias: "AS x" or a bare identifier that is not a clause keyword.
                if ($this->word($tokens[$j] ?? null) === 'AS') {
                    $j += 2;
                } elseif (($tokens[$j][0] ?? null) === 'ident'
                    || (($tokens[$j][0] ?? null) === 'word' && ! in_array($this->word($tokens[$j]), self::CLAUSE_WORDS, true))) {
                    $j++;
                }

                if ($word === 'FROM' && ($tokens[$j][0] ?? null) === ',') {
                    $j++;

                    continue;
                }

                break;
            }
        }

        return $tables;
    }

    /**
     * Names defined in a leading WITH clause.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array<string, true>
     */
    private function cteNames(array $tokens): array
    {
        $names = [];

        if ($this->word($tokens[0] ?? null) !== 'WITH') {
            return $names;
        }

        $i = 1;

        if ($this->word($tokens[$i] ?? null) === 'RECURSIVE') {
            $i++;
        }

        while (isset($tokens[$i]) && in_array($tokens[$i][0], ['word', 'ident'], true)) {
            $names[strtolower($tokens[$i][1])] = true;
            $i++;

            if (($tokens[$i][0] ?? null) === '(') { // column list
                $i = $this->skipParens($tokens, $i);
            }

            if ($this->word($tokens[$i] ?? null) !== 'AS') {
                break;
            }

            $i++;

            while (in_array($this->word($tokens[$i] ?? null), ['NOT', 'MATERIALIZED'], true)) {
                $i++;
            }

            if (($tokens[$i][0] ?? null) !== '(') {
                break;
            }

            $i = $this->skipParens($tokens, $i);

            if (($tokens[$i][0] ?? null) !== ',') {
                break;
            }

            $i++;
        }

        return $names;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return int Index just after the parenthesis that closes the one at $i.
     */
    private function skipParens(array $tokens, int $i): int
    {
        $depth = 0;
        $count = count($tokens);

        for (; $i < $count; $i++) {
            if ($tokens[$i][0] === '(') {
                $depth++;
            } elseif ($tokens[$i][0] === ')' && --$depth === 0) {
                return $i + 1;
            }
        }

        return $count;
    }

    /** @param  array{0: string, 1: string}|null  $token */
    private function word(?array $token): ?string
    {
        return ($token[0] ?? null) === 'word' ? strtoupper($token[1]) : null;
    }

    /**
     * Split into tokens: [type, value]. Types: word, ident (quoted identifier),
     * string, number, or the punctuation character itself.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (($char === '-' && $next === '-') || ($char === '/' && $next === '*') || $char === '#') {
                throw UnsafeQueryException::because('SQL comments are not allowed.');
            }

            if ($char === "'") {
                [$value, $i] = $this->readQuoted($sql, $i, "'");

                if (str_contains($value, '\\')) {
                    // Backslash escapes mean different things in different databases.
                    throw UnsafeQueryException::because('backslashes are not allowed in string literals.');
                }

                $tokens[] = ['string', $value];

                continue;
            }

            if ($char === '"' || $char === '`') {
                [$value, $i] = $this->readQuoted($sql, $i, $char);
                $tokens[] = ['ident', $value];

                continue;
            }

            if ($char === '[') {
                $end = strpos($sql, ']', $i);

                if ($end === false) {
                    throw UnsafeQueryException::because('unterminated [identifier].');
                }

                $tokens[] = ['ident', substr($sql, $i + 1, $end - $i - 1)];
                $i = $end + 1;

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $start = $i;

                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$')) {
                    $i++;
                }

                $tokens[] = ['word', substr($sql, $start, $i - $start)];

                continue;
            }

            if (ctype_digit($char)) {
                $start = $i;

                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '.')) {
                    $i++;
                }

                $tokens[] = ['number', substr($sql, $start, $i - $start)];

                continue;
            }

            if (ord($char) > 127) {
                throw UnsafeQueryException::because('non-ASCII characters are only allowed inside quoted strings or identifiers.');
            }

            $tokens[] = [$char, $char];
            $i++;
        }

        return $tokens;
    }

    /** @return array{0: string, 1: int} The unquoted value and the index after the closing quote. */
    private function readQuoted(string $sql, int $start, string $quote): array
    {
        $length = strlen($sql);
        $value = '';
        $i = $start + 1;

        while ($i < $length) {
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) { // doubled quote is an escaped quote
                    $value .= $quote;
                    $i += 2;

                    continue;
                }

                return [$value, $i + 1];
            }

            $value .= $sql[$i];
            $i++;
        }

        throw UnsafeQueryException::because('unterminated quoted string or identifier.');
    }
}
