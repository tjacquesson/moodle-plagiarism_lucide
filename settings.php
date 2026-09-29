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
 * Site administration page: key, defaults, connection test and queue health.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use plagiarism_lucide\api\api_exception;
use plagiarism_lucide\api\client;
use plagiarism_lucide\form\admin_settings_form;
use plagiarism_lucide\local\queue;
use plagiarism_lucide\local\settings;

require_login();
admin_externalpage_setup('plagiarismlucide');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$pageurl = new moodle_url('/plagiarism/lucide/settings.php');
$form = new admin_settings_form($pageurl);

if ($data = $form->get_data()) {
    $oldkey = (string) settings::get('apikey', '');
    foreach (['enabled', 'apikey', 'defaultenabled', 'maxcredits', 'deleteremote', 'reportretention', 'disclosure'] as $name) {
        set_config($name, $data->$name ?? '', 'plagiarism_lucide');
    }
    if ($oldkey !== (string) $data->apikey) {
        // A new key deserves a fresh start.
        settings::clear_auth_block();
    }
    redirect($pageurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$form->set_data([
    'enabled' => (int) settings::get('enabled', 0),
    'apikey' => (string) settings::get('apikey', ''),
    'defaultenabled' => (int) settings::get('defaultenabled', 0),
    'maxcredits' => settings::max_credits(),
    'deleteremote' => (int) settings::get('deleteremote', 1),
    'reportretention' => (int) settings::get('reportretention', settings::DEFAULT_REPORT_RETENTION),
    'disclosure' => (string) settings::get('disclosure', ''),
]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'plagiarism_lucide'));
echo html_writer::div(get_string('settingsintro', 'plagiarism_lucide'), 'plagiarism-lucide-intro');

if (empty($CFG->enableplagiarism)) {
    echo $OUTPUT->notification(get_string('plagiarismdisabled', 'plagiarism_lucide',
        (new moodle_url('/admin/search.php', ['query' => 'enableplagiarism']))->out()), 'warning');
}
if (settings::delete_right_missing()) {
    echo $OUTPUT->notification(get_string('deleterightmissing', 'plagiarism_lucide'), 'warning');
}
if (settings::auth_block() !== '') {
    echo $OUTPUT->notification(get_string('authblocked', 'plagiarism_lucide', s(settings::auth_block())), 'error');
}

$form->display();

// Connection test: free, sends no student text.
echo $OUTPUT->heading(get_string('connection', 'plagiarism_lucide'), 3);
$test = optional_param('test', 0, PARAM_BOOL);
if ($test && confirm_sesskey()) {
    $key = (string) settings::get('apikey', '');
    if ($key === '') {
        echo $OUTPUT->notification(get_string('apikeyrequired', 'plagiarism_lucide'), 'warning');
    } else {
        try {
            $client = new client($key);
            $capabilities = $client->capabilities();
            $usage = $client->usage();
            settings::clear_auth_block();
            $a = (object) [
                'model' => s($capabilities['model']['name'] ?? '?'),
                'tier' => s($capabilities['limits']['tier'] ?? '?'),
                'maxwords' => (int) ($capabilities['limits']['max_words'] ?? 0),
                'available' => (int) ($usage['credits']['available'] ?? 0),
                'reserved' => (int) ($usage['credits']['reserved'] ?? 0),
            ];
            echo $OUTPUT->notification(get_string('connectionok', 'plagiarism_lucide', $a), 'success');
        } catch (api_exception $e) {
            $a = (object) ['code' => s($e->apicode), 'message' => s($e->getMessage())];
            echo $OUTPUT->notification(get_string('connectionfailed', 'plagiarism_lucide', $a), 'error');
        }
    }
}
echo $OUTPUT->single_button(new moodle_url($pageurl, ['test' => 1, 'sesskey' => sesskey()]),
    get_string('testconnection', 'plagiarism_lucide'), 'get');

// Queue health.
echo $OUTPUT->heading(get_string('queuehealth', 'plagiarism_lucide'), 3);
$counts = $DB->get_records_sql_menu("SELECT status, COUNT(1) FROM {plagiarism_lucide_src} GROUP BY status");
$lines = [];
foreach ([queue::STATUS_QUEUED, queue::STATUS_EXTRACTING, queue::STATUS_ANALYSING, queue::STATUS_BLOCKED,
        queue::STATUS_COMPLETED, queue::STATUS_FAILED, queue::STATUS_UNSUPPORTED] as $status) {
    $lines[] = html_writer::tag('dt', get_string('health_' . $status, 'plagiarism_lucide'))
        . html_writer::tag('dd', (int) ($counts[$status] ?? 0));
}
$lastcron = (int) settings::get('lastcron', 0);
$lines[] = html_writer::tag('dt', get_string('health_lastcron', 'plagiarism_lucide'))
    . html_writer::tag('dd', $lastcron ? userdate($lastcron) : get_string('never'));
$lastcontact = (int) settings::get('lastcontact', 0);
$lines[] = html_writer::tag('dt', get_string('health_lastcontact', 'plagiarism_lucide'))
    . html_writer::tag('dd', $lastcontact ? userdate($lastcontact) : get_string('never'));
$lines[] = html_writer::tag('dt', get_string('health_remotedeletes', 'plagiarism_lucide'))
    . html_writer::tag('dd', $DB->count_records('plagiarism_lucide_del'));
echo html_writer::tag('dl', implode('', $lines), ['class' => 'plagiarism-lucide-details']);
if ($lastcron && $lastcron < time() - 10 * MINSECS) {
    echo $OUTPUT->notification(get_string('cronlate', 'plagiarism_lucide'), 'warning');
}

echo $OUTPUT->footer();
