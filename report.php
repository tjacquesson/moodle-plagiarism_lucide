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
 * Lucide report of one analysed source, for teachers.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use plagiarism_lucide\local\access;
use plagiarism_lucide\local\queue;
use plagiarism_lucide\output\presenter;

$id = required_param('id', PARAM_INT);

$row = $DB->get_record('plagiarism_lucide_src', ['id' => $id], '*', MUST_EXIST);
[$course, $cm] = get_course_and_cm_from_cmid($row->cmid, 'assign');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('plagiarism/lucide:viewreport', $context);

$assign = access::assign_for_cm($cm->id);
if (!access::can_see_submission($assign, (int) $row->userid, (int) $row->groupid)) {
    throw new required_capability_exception($context, 'plagiarism/lucide:viewreport', 'nopermissions', '');
}

$url = new moodle_url('/plagiarism/lucide/report.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('reporttitle', 'plagiarism_lucide') . ' — ' . format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('reporttitle', 'plagiarism_lucide'));
$PAGE->activityheader->disable();

// Never show a report that is not the current content: the badge search already
// matches by content hash, the page must refuse just as strictly.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reporttitle', 'plagiarism_lucide'));

$blind = $assign->is_blind_marking();
if ($row->sourcetype === 'onlinetext') {
    $source = get_string('source_onlinetext', 'plagiarism_lucide');
} else if ($blind) {
    $source = get_string('source_file_hidden', 'plagiarism_lucide');
} else {
    $source = get_string('source_file', 'plagiarism_lucide', s($row->filename));
}

$details = [
    get_string('assignment', 'plagiarism_lucide') => format_string($cm->name),
    get_string('source', 'plagiarism_lucide') => $source,
];

if ($row->status !== queue::STATUS_COMPLETED) {
    echo html_writer::start_div('plagiarism-lucide-report');
    echo html_writer::start_tag('dl', ['class' => 'plagiarism-lucide-details']);
    foreach ($details as $label => $value) {
        echo html_writer::tag('dt', $label) . html_writer::tag('dd', $value);
    }
    echo html_writer::end_tag('dl');
    echo presenter::badge($row);
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

$report = json_decode((string) $row->report, true) ?: [];

$details[get_string('analysedon', 'plagiarism_lucide')] = userdate($row->timecompleted);
if ($row->wordcount) {
    $details[get_string('wordcount', 'plagiarism_lucide')] = get_string('words', 'plagiarism_lucide', (int) $row->wordcount);
}
if ($row->credits !== null) {
    $details[get_string('creditsused', 'plagiarism_lucide')] = (int) $row->credits;
}
$details[get_string('keptuntil', 'plagiarism_lucide')] = userdate($row->expiresat, get_string('strftimedate', 'langconfig'));

echo html_writer::start_div('plagiarism-lucide-report');

// Verdict.
$verdictclass = 'plagiarism-lucide-verdict--' . presenter::verdict_class($row->verdict);
echo html_writer::start_div('plagiarism-lucide-verdict ' . $verdictclass);
echo html_writer::div(get_string('verdict', 'plagiarism_lucide'), 'plagiarism-lucide-verdict-caption');
echo html_writer::div(presenter::verdict_label($row), 'plagiarism-lucide-verdict-label');
if ($row->coverage !== null && $row->coverageprecision !== 'none') {
    echo html_writer::div(get_string('coverage_long', 'plagiarism_lucide', (int) $row->coverage), 'plagiarism-lucide-verdict-line');
}
if ($row->confidence === 'low') {
    echo html_writer::div(get_string('lowconfidence', 'plagiarism_lucide'), 'plagiarism-lucide-verdict-line');
}
echo html_writer::end_div();

echo html_writer::start_tag('dl', ['class' => 'plagiarism-lucide-details']);
foreach ($details as $label => $value) {
    echo html_writer::tag('dt', $label) . html_writer::tag('dd', $value);
}
echo html_writer::end_tag('dl');

echo $OUTPUT->notification(get_string('educationalnotice', 'plagiarism_lucide'), 'info', false);

// Text.
echo $OUTPUT->heading(get_string('analysedtext', 'plagiarism_lucide'), 3);
$reason = $row->coveragereason;
if (!empty($report['segments_available'])) {
    echo html_writer::div(
        html_writer::tag('mark', get_string('legend_ai', 'plagiarism_lucide'),
            ['class' => 'plagiarism-lucide-ai plagiarism-lucide-ai--high']) . ' ' .
        html_writer::tag('mark', get_string('legend_ai_medium', 'plagiarism_lucide'),
            ['class' => 'plagiarism-lucide-ai plagiarism-lucide-ai--medium']),
        'plagiarism-lucide-legend');
} else if (in_array($reason, ['human_verdict', 'diffuse', 'unavailable'], true)) {
    echo html_writer::div(get_string('nosegments_' . $reason, 'plagiarism_lucide'), 'plagiarism-lucide-note');
} else {
    echo html_writer::div(get_string('nosegments_unavailable', 'plagiarism_lucide'), 'plagiarism-lucide-note');
}
echo presenter::highlighted_text($report);

if (!empty($report['model'])) {
    echo html_writer::div(get_string('modelinfo', 'plagiarism_lucide', (object) [
        'model' => s($report['model']),
        'policy' => s($report['policy'] ?? ''),
    ]), 'plagiarism-lucide-footer');
}

echo html_writer::end_div();
echo $OUTPUT->footer();
