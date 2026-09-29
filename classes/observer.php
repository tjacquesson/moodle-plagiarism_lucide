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

namespace plagiarism_lucide;

use plagiarism_lucide\local\queue;
use plagiarism_lucide\local\settings;

/**
 * Event observers. Local writes only, never a network call.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A work was handed in: read it once the grouping delay is over.
     *
     * Moodle fires this event on the final "submit" click, and on every save
     * when the assignment does not require that click (editable submission).
     *
     * @param \mod_assign\event\assessable_submitted $event
     */
    public static function assessable_submitted(\mod_assign\event\assessable_submitted $event): void {
        if (!settings::is_configured()) {
            return;
        }
        $cmid = (int) $event->contextinstanceid;
        if (!settings::is_enabled_for_cm($cmid)) {
            return;
        }
        $editable = !empty($event->other['submission_editable']);
        queue::request_sync($cmid, (int) $event->objectid, $editable ? queue::DEBOUNCE : 0);
    }

    /**
     * A teacher removed a submission: its reports go too.
     *
     * @param \mod_assign\event\submission_removed $event
     */
    public static function submission_removed(\mod_assign\event\submission_removed $event): void {
        global $DB;
        $submissionid = (int) $event->objectid;
        queue::delete_sources($DB->get_records('plagiarism_lucide_src', ['submissionid' => $submissionid]));
        $DB->delete_records('plagiarism_lucide_sync', ['submissionid' => $submissionid]);
    }

    /**
     * An activity was deleted.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;
        $cmid = (int) $event->objectid;
        queue::delete_sources($DB->get_records('plagiarism_lucide_src', ['cmid' => $cmid]));
        $DB->delete_records('plagiarism_lucide_sync', ['cmid' => $cmid]);
        $DB->delete_records('plagiarism_lucide_cm', ['cmid' => $cmid]);
    }

    /**
     * A user account was deleted.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        queue::delete_sources($DB->get_records('plagiarism_lucide_src', ['userid' => (int) $event->objectid]));
    }
}
