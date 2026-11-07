<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Writes a .sql file (structure + data) without needing mysqldump on the server.
 *  - a company's own database: every table
 *  - the shared main database: only that company's rows of the company tables
 */
class DatabaseDump
{
    public static function write(Connection $conn, string $database, array $tables, ?int $onlyCompany, callable $out): void
    {
        $pdo = $conn->getPdo();
        $out("-- MK Electrical database dump\n-- Database: $database\n-- Date: " . now()->toDateTimeString() . "\n"
            . ($onlyCompany ? "-- Rows of company id $onlyCompany only\n" : '')
            . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach ($tables as $table) {
            $create = $conn->selectOne("SHOW CREATE TABLE `$table`");
            $create = (array) $create;
            $sql = $create['Create Table'] ?? array_values($create)[1];
            $sql = self::withoutOutsideKeys($sql, $tables);
            $out("-- ----------------------------\n-- Table: $table\n-- ----------------------------\nDROP TABLE IF EXISTS `$table`;\n$sql;\n\n");

            $query = $conn->table($table);
            if ($onlyCompany) {
                $query->where('company_id', $onlyCompany);
            }
            $batch = [];
            $columns = null;
            foreach ($query->cursor() as $row) {
                $row = (array) $row;
                $columns ??= '`' . implode('`, `', array_keys($row)) . '`';
                $batch[] = '(' . implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)) . ')';
                if (count($batch) >= 200) {
                    $out("INSERT INTO `$table` ($columns) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $out("INSERT INTO `$table` ($columns) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $out("\n");
        }
        $out("SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /** drop foreign keys that point to tables which are not part of this dump (users, companies ...) */
    private static function withoutOutsideKeys(string $sql, array $tables): string
    {
        $lines = explode("\n", $sql);
        $keep = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*CONSTRAINT .* REFERENCES `([^`]+)`/', $line, $m) && !in_array($m[1], $tables, true)) {
                continue;
            }
            $keep[] = $line;
        }
        // the last column / key line before ") ENGINE" must not end with a comma
        foreach ($keep as $i => $line) {
            if (str_starts_with($line, ')') && $i > 0) {
                $keep[$i - 1] = rtrim($keep[$i - 1], ',');
            }
        }

        return implode("\n", $keep);
    }
}
