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
use plagiarism_lucide\local\settings;

/**
 * Every minute: read handed-in works, send them, collect reports.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_queue extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_process_queue', 'plagiarism_lucide');
    }

    /**
     * Run the task. Bounded work, then hand back to cron.
     */
    public function execute() {
        set_config('lastcron', time(), 'plagiarism_lucide');
        if (!settings::is_configured()) {
            return;
        }
        $read = queue::sync_due(100);
        $stats = queue::process(50);
        mtrace(sprintf(
            'plagiarism_lucide: %d submission(s) read, %d sent, %d completed, %d not analysable, %d waiting%s',
            $read,
            $stats['sent'],
            $stats['completed'],
            $stats['failed'],
            $stats['waiting'],
            settings::auth_block() !== '' ? ' — sending stopped: ' . settings::auth_block() : ''
        ));
    }
}
