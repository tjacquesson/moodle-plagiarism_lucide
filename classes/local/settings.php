<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace plagiarism_lucide\local;

/**
 * Access to site and activity settings.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {
    /** Default ceiling of credits for one source: 20 000 words at 1 credit per 100 words. */
    public const DEFAULT_MAX_CREDITS = 200;
    /** Default local report lifetime in days. */
    public const DEFAULT_REPORT_RETENTION = 365;
    /** Retention requested from Lucide; the copy is normally erased as soon as the report is fetched. */
    public const REMOTE_RETENTION = '7d';

    /**
     * Read one plugin setting.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $name, $default = null) {
        $value = get_config('plagiarism_lucide', $name);
        return ($value === false || $value === null) ? $default : $value;
    }

    /**
     * Whether the site administrator switched the plugin on and entered a key.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        global $CFG;
        return !empty($CFG->enableplagiarism) && self::get('enabled') && trim((string) self::get('apikey', '')) !== '';
    }

    /**
     * Whether Lucide is switched on for one activity.
     *
     * @param int $cmid
     * @return bool
     */
    public static function is_enabled_for_cm(int $cmid): bool {
        global $DB;
        static $cache = [];
        if (!array_key_exists($cmid, $cache) || PHPUNIT_TEST) {
            $cache[$cmid] = (bool) $DB->get_field('plagiarism_lucide_cm', 'enabled', ['cmid' => $cmid]);
        }
        return $cache[$cmid];
    }

    /**
     * Switch Lucide on or off for one activity.
     *
     * @param int $cmid
     * @param bool $enabled
     */
    public static function set_enabled_for_cm(int $cmid, bool $enabled): void {
        global $DB;
        $record = $DB->get_record('plagiarism_lucide_cm', ['cmid' => $cmid]);
        if ($record) {
            $record->enabled = (int) $enabled;
            $record->timemodified = time();
            $DB->update_record('plagiarism_lucide_cm', $record);
        } else {
            $DB->insert_record('plagiarism_lucide_cm', (object) [
                'cmid' => $cmid,
                'enabled' => (int) $enabled,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Credit ceiling sent with each analysis.
     *
     * @return int
     */
    public static function max_credits(): int {
        return max(1, (int) self::get('maxcredits', self::DEFAULT_MAX_CREDITS));
    }

    /**
     * Local report lifetime in seconds.
     *
     * @return int
     */
    public static function report_retention(): int {
        return max(1, (int) self::get('reportretention', self::DEFAULT_REPORT_RETENTION)) * DAYSECS;
    }

    /**
     * Whether to erase the copy at Lucide once its report is stored in Moodle.
     *
     * @return bool
     */
    public static function delete_remote_after_fetch(): bool {
        return (bool) self::get('deleteremote', 1);
    }

    /**
     * Stop all sending until an administrator fixes the key or the subscription.
     *
     * @param string $code
     */
    public static function block_auth(string $code): void {
        set_config('authblocked', $code, 'plagiarism_lucide');
        set_config('authblockedat', time(), 'plagiarism_lucide');
    }

    /**
     * Resume sending after a successful connection test or a new key.
     */
    public static function clear_auth_block(): void {
        unset_config('authblocked', 'plagiarism_lucide');
        unset_config('authblockedat', 'plagiarism_lucide');
    }

    /**
     * Current authentication block, empty when none.
     *
     * @return string
     */
    public static function auth_block(): string {
        return (string) self::get('authblocked', '');
    }

    /**
     * Pause one kind of call after a 429 or a credit shortage.
     *
     * @param string $kind send (uploads and admissions) or read (status and reports)
     * @param int $seconds
     */
    public static function back_off(string $kind, int $seconds): void {
        set_config('backoff' . $kind, time() + max(5, min($seconds, HOURSECS)), 'plagiarism_lucide');
    }

    /**
     * Whether one kind of call is paused.
     *
     * @param string $kind send or read
     * @return bool
     */
    public static function is_backing_off(string $kind): bool {
        return (int) self::get('backoff' . $kind, 0) > time();
    }

    /**
     * Remember whether the key may erase analyses at Lucide.
     *
     * @param bool $allowed
     */
    public static function note_delete_right(bool $allowed): void {
        if ($allowed) {
            unset_config('deleterightmissing', 'plagiarism_lucide');
        } else {
            set_config('deleterightmissing', time(), 'plagiarism_lucide');
        }
    }

    /**
     * Whether Lucide refused an erasure because the key lacks the right.
     *
     * @return bool
     */
    public static function delete_right_missing(): bool {
        return (bool) self::get('deleterightmissing', 0);
    }
}
