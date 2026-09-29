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
 * Entry points called by Moodle core.
 *
 * Nothing here calls Lucide: badges and forms only read and write local tables.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/lib.php');

use plagiarism_lucide\local\access;
use plagiarism_lucide\local\queue;
use plagiarism_lucide\local\settings;
use plagiarism_lucide\local\submission_collector;
use plagiarism_lucide\output\presenter;

/**
 * Lucide plagiarism-API plugin: AI-generated text detection for assignments.
 */
class plagiarism_plugin_lucide extends plagiarism_plugin {

    /**
     * Badge shown next to a submitted file or online text, for teachers only.
     *
     * @param array $linkarray cmid, userid, and file (stored_file) or content (online text HTML)
     * @return string HTML
     */
    public function get_links($linkarray) {
        global $DB;

        $cmid = (int) ($linkarray['cmid'] ?? 0);
        if (!$cmid || !settings::is_configured() || !settings::is_enabled_for_cm($cmid)) {
            return '';
        }
        $context = context_module::instance($cmid);
        if (!has_capability('plagiarism/lucide:viewreport', $context)) {
            return '';
        }

        $row = null;
        $submissionid = 0;
        if (!empty($linkarray['file']) && $linkarray['file'] instanceof stored_file) {
            $file = $linkarray['file'];
            if ($file->get_component() !== 'assignsubmission_file' || $file->get_filearea() !== 'submission_files') {
                return '';
            }
            $submissionid = (int) $file->get_itemid();
            $row = $DB->get_record('plagiarism_lucide_src', [
                'submissionid' => $submissionid,
                'identifier' => $file->get_pathnamehash(),
                'contenthash' => $file->get_contenthash(),
            ]);
        } else if (isset($linkarray['content'])) {
            if (trim(html_to_text((string) $linkarray['content'], 0, false)) === '') {
                // Empty editor next to a file submission: nothing to analyse.
                return '';
            }
            $userid = (int) ($linkarray['userid'] ?? 0);
            $rows = $DB->get_records('plagiarism_lucide_src', [
                'cmid' => $cmid,
                'sourcetype' => 'onlinetext',
                'userid' => $userid,
                'contenthash' => submission_collector::text_hash((string) $linkarray['content']),
            ], 'id DESC', '*', 0, 1);
            $row = $rows ? reset($rows) : null;
            if (!$row && $userid && !empty($linkarray['assignment'])) {
                $submissionid = (int) $DB->get_field('assign_submission', 'id',
                    ['assignment' => $linkarray['assignment'], 'userid' => $userid, 'latest' => 1]);
            }
        } else {
            return '';
        }

        try {
            $assign = access::assign_for_cm($cmid);
            if ($row) {
                if (!access::can_see_submission($assign, (int) $row->userid, (int) $row->groupid)) {
                    return '';
                }
                return presenter::badge($row);
            }
            return presenter::badge(null, $this->scan_url($assign, $submissionid));
        } catch (moodle_exception $e) {
            return '';
        }
    }

    /**
     * Manual analysis link for a handed-in work that has no analysis yet.
     *
     * @param assign $assign
     * @param int $submissionid
     * @return moodle_url|null
     */
    protected function scan_url(assign $assign, int $submissionid): ?moodle_url {
        global $DB;
        if (!$submissionid) {
            return null;
        }
        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED || empty($submission->latest)) {
            return null;
        }
        if (!access::can_see_submission($assign, (int) $submission->userid, (int) $submission->groupid,
                'plagiarism/lucide:requestscan')) {
            return null;
        }
        return new moodle_url('/plagiarism/lucide/actions.php', [
            'action' => 'scan',
            'cmid' => $assign->get_course_module()->id,
            'submissionid' => $submissionid,
            'sesskey' => sesskey(),
        ]);
    }

    /**
     * Notice shown to students before they hand in their work.
     *
     * @param int $cmid
     * @return string HTML
     */
    public function print_disclosure($cmid) {
        global $OUTPUT;
        if (!settings::is_configured() || !settings::is_enabled_for_cm((int) $cmid)) {
            return '';
        }
        $custom = trim((string) settings::get('disclosure', ''));
        $text = $custom !== '' ? format_text($custom, FORMAT_PLAIN) : get_string('disclosure_default', 'plagiarism_lucide');
        return $OUTPUT->box($text, 'generalbox plagiarism-lucide-disclosure');
    }
}

/**
 * Add the Lucide switch to the assignment settings form.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 */
function plagiarism_lucide_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    if (!settings::is_configured()) {
        return;
    }
    $current = $formwrapper->get_current();
    $modulename = $current->modulename ?? ($formwrapper->get_coursemodule()->modname ?? '');
    if ($modulename !== 'assign') {
        return;
    }
    $context = $formwrapper->get_context();
    if (!has_capability('plagiarism/lucide:enable', $context)) {
        return;
    }

    $cmid = !empty($current->coursemodule) ? (int) $current->coursemodule : 0;
    $mform->addElement('header', 'plagiarism_lucide_header', get_string('formheader', 'plagiarism_lucide'));
    $mform->addElement('advcheckbox', 'lucide_enabled', get_string('enableforactivity', 'plagiarism_lucide'));
    $mform->addHelpButton('lucide_enabled', 'enableforactivity', 'plagiarism_lucide');
    if ($cmid) {
        $enabled = (int) $DB->get_field('plagiarism_lucide_cm', 'enabled', ['cmid' => $cmid]);
    } else {
        $enabled = (int) settings::get('defaultenabled', 0);
    }
    $mform->setDefault('lucide_enabled', $enabled);

    if ($cmid) {
        $waiting = count(queue::unanalysed_submissions($cmid));
        if ($waiting > 0) {
            $mform->addElement('advcheckbox', 'lucide_scanexisting',
                get_string('scanexisting', 'plagiarism_lucide', $waiting));
            $mform->addHelpButton('lucide_scanexisting', 'scanexisting', 'plagiarism_lucide');
            $mform->setDefault('lucide_scanexisting', 0);
            $mform->hideIf('lucide_scanexisting', 'lucide_enabled', 'notchecked');
        }
    }
}

/**
 * Save the Lucide switch of an assignment.
 *
 * @param stdClass $data
 * @param stdClass $course
 * @return stdClass
 */
function plagiarism_lucide_coursemodule_edit_post_actions($data, $course) {
    global $USER;

    if (($data->modulename ?? '') !== 'assign' || !isset($data->lucide_enabled) || empty($data->coursemodule)) {
        return $data;
    }
    $context = context_module::instance($data->coursemodule);
    if (!has_capability('plagiarism/lucide:enable', $context)) {
        return $data;
    }
    settings::set_enabled_for_cm((int) $data->coursemodule, !empty($data->lucide_enabled));
    if (!empty($data->lucide_enabled) && !empty($data->lucide_scanexisting)) {
        queue::request_existing((int) $data->coursemodule, (int) $USER->id);
    }
    return $data;
}
