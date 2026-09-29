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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Who may see a report or request an analysis.
 *
 * The Lucide capability is added to the assignment's own rules, never
 * substituted for them: the viewer must also be allowed to see that
 * submission, including separate groups.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {

    /**
     * Whether the current user may see what Lucide says about a submission.
     *
     * @param \assign $assign
     * @param int $userid submission owner, 0 for a group submission
     * @param int $groupid
     * @param string $capability
     * @return bool
     */
    public static function can_see_submission(\assign $assign, int $userid, int $groupid,
            string $capability = 'plagiarism/lucide:viewreport'): bool {
        global $USER;

        $context = $assign->get_context();
        if (!has_capability($capability, $context)) {
            return false;
        }
        if (!has_any_capability(['mod/assign:grade', 'mod/assign:viewgrades'], $context)) {
            return false;
        }

        if ($userid) {
            if (!$assign->can_view_submission($userid)) {
                return false;
            }
        } else if (!$assign->can_view_group_submission($groupid)) {
            return false;
        }

        $cm = $assign->get_course_module();
        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $context)) {
            $mine = array_keys(groups_get_all_groups($cm->course, $USER->id, $cm->groupingid, 'g.id'));
            if ($userid) {
                $theirs = array_keys(groups_get_all_groups($cm->course, $userid, $cm->groupingid, 'g.id'));
            } else {
                $theirs = [$groupid];
            }
            if (!array_intersect($mine, $theirs)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build the assignment object of a course module.
     *
     * @param int $cmid
     * @return \assign
     */
    public static function assign_for_cm(int $cmid): \assign {
        // The grading table asks once per file: build each assignment once per request.
        static $cache = [];
        if (!isset($cache[$cmid]) || PHPUNIT_TEST) {
            [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
            $cache[$cmid] = new \assign(\context_module::instance($cm->id), $cm, $course);
        }
        return $cache[$cmid];
    }
}
