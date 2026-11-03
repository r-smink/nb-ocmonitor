<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Backup;

/**
 * Database-export en -import voor de backup vóór updates.
 *
 * Export: alle tabellen met het WordPress-prefix, behalve de eigen tabellen van deze plugin
 * (wijzigingslog en nonces) en de eigen opties (koppeling en sleutels). Eén statement per regel.
 *
 * Import: eerst alles in tijdelijke tabellen (nbocmtmp_*). Pas als dat helemaal gelukt is, worden
 * de tabellen in één RENAME TABLE omgewisseld. Mislukt de import halverwege, dan blijft de site
 * zoals hij was. De huidige koppeling (nb_ocm_*-opties) gaat mee naar de teruggezette tabel.
 */
final class DatabaseDump
{
    public const TMP_PREFIX = 'nbocmtmp_';
    public const OLD_PREFIX = 'nbocmold_';
    private const BATCH = 300;
    private const OWN_OPTIONS = 'nb\\_ocm\\_%';

    /** @return array{tables: int, rows: int} */
    public static function export(string $path): array
    {
        global $wpdb;
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Kan databasebackup niet schrijven');
        }
        $rows = 0;
        $tables = self::tables();
        try {
            gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($tables as $table) {
                $create = $wpdb->get_row('SHOW CREATE TABLE `' . self::ident($table) . '`', ARRAY_N);
                if (!is_array($create) || !isset($create[1])) {
                    throw new \RuntimeException('Kan structuur van ' . $table . ' niet lezen');
                }
                gzwrite($gz, 'DROP TABLE IF EXISTS `' . self::ident($table) . "`;\n" . str_replace(["\r", "\n"], ' ', (string) $create[1]) . ";\n");

                $where = $table === $wpdb->options ? $wpdb->prepare(' WHERE option_name NOT LIKE %s', self::OWN_OPTIONS) : '';
                for ($offset = 0; ; $offset += self::BATCH) {
                    $batch = $wpdb->get_results('SELECT * FROM `' . self::ident($table) . '`' . $where . ' LIMIT ' . $offset . ', ' . self::BATCH, ARRAY_N);
                    if ($wpdb->last_error !== '') {
                        throw new \RuntimeException('Fout bij lezen van ' . $table . ': ' . $wpdb->last_error);
                    }
                    if (!$batch) {
                        break;
                    }
                    $values = [];
                    foreach ($batch as $row) {
                        $values[] = '(' . implode(',', array_map([self::class, 'value'], $row)) . ')';
                    }
                    gzwrite($gz, 'INSERT INTO `' . self::ident($table) . '` VALUES ' . implode(',', $values) . ";\n");
                    $rows += count($batch);
                    if (count($batch) < self::BATCH) {
                        break;
                    }
                }
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            gzclose($gz);
        }

        return ['tables' => count($tables), 'rows' => $rows];
    }

    /** @return array{tables: int} */
    public static function import(string $path): array
    {
        global $wpdb;
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Kan databasebackup niet lezen');
        }
        $restored = [];
        try {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
            $buffer = '';
            while (!gzeof($gz)) {
                $buffer .= (string) gzgets($gz, 8 * 1048576);
                if (substr($buffer, -1) !== "\n" && !gzeof($gz)) {
                    continue;
                }
                $statement = rtrim($buffer, "\r\n");
                $buffer = '';
                if ($statement === '' || strpos($statement, 'SET ') === 0) {
                    continue;
                }
                [$sql, $table] = self::toTemporary($statement);
                if ($sql === null) {
                    throw new \RuntimeException('Onbekend statement in de backup');
                }
                if ($wpdb->query($sql) === false) {
                    throw new \RuntimeException('Import mislukt bij ' . $table . ': ' . $wpdb->last_error);
                }
                $restored[$table] = true;
            }
            $tables = array_keys($restored);
            if (!in_array($wpdb->options, $tables, true)) {
                throw new \RuntimeException('Backup bevat geen optietabel; terugzetten afgebroken');
            }

            // Koppeling en sleutels van nu bewaren in de teruggezette optietabel.
            $own = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value, autoload FROM `' . self::ident($wpdb->options) . '` WHERE option_name LIKE %s', self::OWN_OPTIONS), ARRAY_A);
            foreach ((array) $own as $option) {
                $wpdb->replace(self::TMP_PREFIX . $wpdb->options, $option);
            }

            // Omwisselen in één statement: nu → oud, tijdelijk → nu.
            $renames = [];
            foreach ($tables as $table) {
                $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
                if ($exists) {
                    $wpdb->query('DROP TABLE IF EXISTS `' . self::ident(self::OLD_PREFIX . $table) . '`');
                    $renames[] = '`' . self::ident($table) . '` TO `' . self::ident(self::OLD_PREFIX . $table) . '`';
                }
                $renames[] = '`' . self::ident(self::TMP_PREFIX . $table) . '` TO `' . self::ident($table) . '`';
            }
            if ($wpdb->query('RENAME TABLE ' . implode(', ', $renames)) === false) {
                throw new \RuntimeException('Omwisselen van de tabellen mislukt: ' . $wpdb->last_error);
            }
            foreach ($tables as $table) {
                $wpdb->query('DROP TABLE IF EXISTS `' . self::ident(self::OLD_PREFIX . $table) . '`');
            }
            wp_cache_flush();

            return ['tables' => count($tables)];
        } catch (\Throwable $e) {
            foreach (array_keys($restored) as $table) {
                $wpdb->query('DROP TABLE IF EXISTS `' . self::ident(self::TMP_PREFIX . $table) . '`');
            }
            throw $e;
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            gzclose($gz);
        }
    }

    /**
     * Zet een statement uit onze eigen export om naar de tijdelijke tabel. Alleen de drie vormen die
     * export() schrijft worden geaccepteerd.
     *
     * @return array{0: ?string, 1: string} [sql, oorspronkelijke tabelnaam]
     */
    public static function toTemporary(string $statement): array
    {
        if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([A-Za-z0-9_$]{1,64})`/', $statement, $m) !== 1) {
            return [null, ''];
        }
        $table = $m[2];
        $tmp = self::TMP_PREFIX . $table;
        if (strlen($tmp) > 64) {
            return [null, $table];
        }

        return [$m[1] . ' `' . $tmp . '`' . substr($statement, strlen($m[0])), $table];
    }

    /** @return string[] */
    private static function tables(): array
    {
        global $wpdb;
        $own = [$wpdb->prefix . 'nb_ocm_events', $wpdb->prefix . 'nb_ocm_nonces'];
        $all = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));

        return array_values(array_filter($all, static function ($t) use ($own): bool {
            return is_string($t) && !in_array($t, $own, true) && strpos($t, self::TMP_PREFIX) !== 0 && strpos($t, self::OLD_PREFIX) !== 0;
        }));
    }

    /** @param mixed $v */
    private static function value($v): string
    {
        global $wpdb;
        if ($v === null) {
            return 'NULL';
        }

        return "'" . $wpdb->remove_placeholder_escape($wpdb->_real_escape((string) $v)) . "'";
    }

    private static function ident(string $name): string
    {
        return str_replace('`', '', $name);
    }
}
