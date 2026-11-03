<?php

declare(strict_types=1);

namespace NextBuzz\OcMonitor\Client\Commands;

use NextBuzz\OcMonitor\Client\CommandContext;
use NextBuzz\OcMonitor\Client\CommandError;
use NextBuzz\OcMonitor\Client\CommandRegistry;
use NextBuzz\OcMonitor\Client\Events\EventRecorder;

/**
 * Database opschonen (scope "maintenance"). Alleen veilige categorieën, met een leeftijdsgrens,
 * in porties. Media worden alleen geteld: page builders verwijzen naar afbeeldingen op manieren
 * die niet betrouwbaar te herkennen zijn, dus automatisch verwijderen zou te riskant zijn.
 */
final class CleanupCommands
{
    public const ITEMS = [
        'revisions' => 'Revisies ouder dan 30 dagen',
        'auto_drafts' => 'Automatische concepten ouder dan 7 dagen',
        'trash' => 'Berichten in de prullenbak ouder dan 30 dagen',
        'spam' => 'Spamreacties',
        'trashed_comments' => 'Reacties in de prullenbak',
        'expired_transients' => 'Verlopen tijdelijke gegevens (transients)',
        'orphan_postmeta' => 'Losse berichtgegevens zonder bericht',
        'orphan_commentmeta' => 'Losse reactiegegevens zonder reactie',
    ];
    private const BATCH = 2000;

    private EventRecorder $recorder;

    public function __construct(EventRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public function registerOn(CommandRegistry $registry): void
    {
        $registry->add('db.cleanup', 'maintenance', [$this, 'cleanup']);
    }

    /**
     * Tellingen voor de inventaris.
     *
     * @return array<string, mixed>
     */
    public static function stats(): array
    {
        global $wpdb;
        $counts = [];
        foreach (array_keys(self::ITEMS) as $item) {
            $counts[$item] = (int) $wpdb->get_var(self::sql($item, true));
        }
        $size = $wpdb->get_row($wpdb->prepare(
            'SELECT SUM(data_length + index_length) AS total, SUM(data_free) AS free FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
            DB_NAME,
            $wpdb->esc_like($wpdb->prefix) . '%'
        ), ARRAY_A);

        return [
            'counts' => $counts,
            'db_bytes' => isset($size['total']) ? (int) $size['total'] : null,
            'autoload_bytes' => (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')"),
            'media' => [
                'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"),
                'unattached' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0"),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args {items: string[]}
     * @return array<string, mixed>
     */
    public function cleanup(array $args, CommandContext $context): array
    {
        if (array_diff(array_keys($args), ['items']) !== []) {
            throw new CommandError('bad_request', 'Alleen items is toegestaan');
        }
        $items = $args['items'] ?? null;
        if (!is_array($items) || $items === [] || array_diff($items, array_keys(self::ITEMS)) !== []) {
            throw new CommandError('bad_request', 'Kies uit ' . implode(', ', array_keys(self::ITEMS)));
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(600); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        $deleted = [];
        $more = false;
        foreach (array_values(array_unique($items)) as $item) {
            $deleted[$item] = $this->clean($item);
            $more = $more || $deleted[$item] >= self::BATCH;
        }
        $this->recorder->record('db.cleaned', implode(',', array_keys($deleted)), null, null, 'success', ['deleted' => $deleted]);

        return ['deleted' => $deleted, 'more' => $more, 'stats' => self::stats()];
    }

    private function clean(string $item): int
    {
        global $wpdb;
        switch ($item) {
            case 'revisions':
            case 'auto_drafts':
            case 'trash':
                $ids = $wpdb->get_col(self::sql($item, false) . ' LIMIT ' . self::BATCH);
                foreach ($ids as $id) {
                    $item === 'revisions' ? wp_delete_post_revision((int) $id) : wp_delete_post((int) $id, true);
                }
                return count($ids);
            case 'spam':
            case 'trashed_comments':
                $ids = $wpdb->get_col(self::sql($item, false) . ' LIMIT ' . self::BATCH);
                foreach ($ids as $id) {
                    wp_delete_comment((int) $id, true);
                }
                return count($ids);
            case 'expired_transients':
                $before = (int) $wpdb->get_var(self::sql($item, true));
                delete_expired_transients(true);
                return $before;
            case 'orphan_postmeta':
                return (int) $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
            case 'orphan_commentmeta':
                return (int) $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL");
        }

        return 0;
    }

    /** SELECT voor telling ($count) of id's. Alleen vaste SQL, geen invoer van buiten. */
    private static function sql(string $item, bool $count): string
    {
        global $wpdb;
        $now = time();
        $days = static function (int $n) use ($now): string {
            return gmdate('Y-m-d H:i:s', $now - $n * DAY_IN_SECONDS);
        };
        switch ($item) {
            case 'revisions':
                return ($count ? 'SELECT COUNT(*)' : 'SELECT ID') . " FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < '" . $days(30) . "'";
            case 'auto_drafts':
                return ($count ? 'SELECT COUNT(*)' : 'SELECT ID') . " FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified_gmt < '" . $days(7) . "'";
            case 'trash':
                return ($count ? 'SELECT COUNT(*)' : 'SELECT ID') . " FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified_gmt < '" . $days(30) . "'";
            case 'spam':
                return ($count ? 'SELECT COUNT(*)' : 'SELECT comment_ID') . " FROM {$wpdb->comments} WHERE comment_approved = 'spam'";
            case 'trashed_comments':
                return ($count ? 'SELECT COUNT(*)' : 'SELECT comment_ID') . " FROM {$wpdb->comments} WHERE comment_approved = 'trash'";
            case 'expired_transients':
                return "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND option_value < " . $now;
            case 'orphan_postmeta':
                return "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL";
            case 'orphan_commentmeta':
                return "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL";
        }

        return 'SELECT 0';
    }
}
