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

namespace plagiarism_lucide\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use plagiarism_lucide\local\queue;

/**
 * Privacy API: what the plugin stores, what it sends to Lucide, export and deletion.
 *
 * A group submission belongs to every member: a request from one member
 * erases the Lucide report of the group's source for everyone.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_plagiarism\privacy\plagiarism_provider,
        \core_plagiarism\privacy\plagiarism_user_provider {

    /**
     * Describe the stored and transmitted data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('plagiarism_lucide_src', [
            'userid' => 'privacy:metadata:plagiarism_lucide_src:userid',
            'submissionid' => 'privacy:metadata:plagiarism_lucide_src:submissionid',
            'filename' => 'privacy:metadata:plagiarism_lucide_src:filename',
            'verdict' => 'privacy:metadata:plagiarism_lucide_src:verdict',
            'score' => 'privacy:metadata:plagiarism_lucide_src:score',
            'report' => 'privacy:metadata:plagiarism_lucide_src:report',
            'timecreated' => 'privacy:metadata:plagiarism_lucide_src:timecreated',
        ], 'privacy:metadata:plagiarism_lucide_src');

        $collection->add_external_location_link('lucide', [
            'content' => 'privacy:metadata:lucide:content',
            'file' => 'privacy:metadata:lucide:file',
        ], 'privacy:metadata:lucide');

        return $collection;
    }

    /**
     * Export the Lucide data of a user's submissions in an assignment.
     *
     * @param int $userid
     * @param \context $context
     * @param array $subcontext
     * @param array $linkarray
     */
    public static function export_plagiarism_user_data(int $userid, \context $context, array $subcontext, array $linkarray) {
        global $DB;
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $rows = self::rows_for_user($userid, (int) $context->instanceid);
        foreach ($rows as $row) {
            $report = json_decode((string) $row->report, true) ?: [];
            $data = (object) [
                'source' => $row->sourcetype === 'file' ? $row->filename : 'onlinetext',
                'status' => $row->status,
                'verdict' => $row->verdict,
                'human_likeness_score' => $row->score,
                'highlighted_ai_percent' => $row->coverage,
                'word_count' => $row->wordcount,
                'analysed_text' => $report['text'] ?? null,
                'analysed_on' => $row->timecompleted ? \core_privacy\local\request\transform::datetime($row->timecompleted) : null,
                'report_expires_on' => $row->expiresat ? \core_privacy\local\request\transform::datetime($row->expiresat) : null,
            ];
            writer::with_context($context)->export_data(
                array_merge($subcontext, [get_string('privacy:export', 'plagiarism_lucide'), $row->id]), $data);
        }
    }

    /**
     * Delete all Lucide data of an activity.
     *
     * @param \context $context
     */
    public static function delete_plagiarism_for_context(\context $context) {
        global $DB;
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        queue::delete_sources($DB->get_records('plagiarism_lucide_src', ['cmid' => $context->instanceid]));
        $DB->delete_records('plagiarism_lucide_sync', ['cmid' => $context->instanceid]);
    }

    /**
     * Delete the Lucide data of one user in an activity.
     *
     * @param int $userid
     * @param \context $context
     */
    public static function delete_plagiarism_for_user(int $userid, \context $context) {
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        queue::delete_sources(self::rows_for_user($userid, (int) $context->instanceid));
    }

    /**
     * Delete the Lucide data of several users in an activity.
     *
     * @param array $userids
     * @param \context $context
     */
    public static function delete_plagiarism_for_users(array $userids, \context $context) {
        foreach ($userids as $userid) {
            self::delete_plagiarism_for_user((int) $userid, $context);
        }
    }

    /**
     * Sources of a user in an activity, including the group submissions of the user's groups.
     *
     * @param int $userid
     * @param int $cmid
     * @return \stdClass[]
     */
    protected static function rows_for_user(int $userid, int $cmid): array {
        global $DB;
        $rows = $DB->get_records('plagiarism_lucide_src', ['cmid' => $cmid, 'userid' => $userid]);
        $cm = get_coursemodule_from_id('assign', $cmid);
        if ($cm) {
            $groups = array_keys(groups_get_all_groups($cm->course, $userid, 0, 'g.id'));
            if ($groups) {
                [$insql, $params] = $DB->get_in_or_equal($groups, SQL_PARAMS_NAMED);
                $params['cmid'] = $cmid;
                $rows += $DB->get_records_select('plagiarism_lucide_src',
                    "cmid = :cmid AND userid = 0 AND groupid $insql", $params);
            }
        }
        return $rows;
    }
}
