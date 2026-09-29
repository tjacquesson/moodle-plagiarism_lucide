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

namespace plagiarism_lucide\task;

use plagiarism_lucide\local\queue;

/**
 * Daily: purge expired reports and confirm remote deletions.
 *
 * Remote deletions run even when the plugin is switched off, as long as a key remains.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup', 'plagiarism_lucide');
    }

    /**
     * Run the task.
     */
    public function execute() {
        $purged = queue::purge_expired();
        $deleted = queue::process_remote_deletions();
        mtrace("plagiarism_lucide: {$purged} expired report(s) purged, {$deleted} remote deletion(s) confirmed");
    }
}
