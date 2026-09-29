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

/**
 * Teacher actions: analyse a submission that was handed in before Lucide was switched on.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use plagiarism_lucide\local\access;
use plagiarism_lucide\local\queue;
use plagiarism_lucide\local\settings;

$action = required_param('action', PARAM_ALPHA);
$cmid = required_param('cmid', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
require_login($course, false, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('plagiarism/lucide:requestscan', $context);

if ($action !== 'scan') {
    throw new moodle_exception('invalidaction', 'error');
}
if (!settings::is_configured() || !settings::is_enabled_for_cm($cm->id)) {
    throw new moodle_exception('notenabled', 'plagiarism_lucide');
}

$submission = $DB->get_record('assign_submission', ['id' => $submissionid, 'assignment' => $cm->instance], '*', MUST_EXIST);
$assign = access::assign_for_cm($cm->id);
if (!access::can_see_submission($assign, (int) $submission->userid, (int) $submission->groupid,
        'plagiarism/lucide:requestscan')) {
    throw new required_capability_exception($context, 'plagiarism/lucide:requestscan', 'nopermissions', '');
}

queue::request_sync($cm->id, $submissionid, 0, (int) $USER->id);

$return = get_local_referer(false) ?: new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']);
redirect($return, get_string('scanrequested', 'plagiarism_lucide'), null, \core\output\notification::NOTIFY_SUCCESS);
