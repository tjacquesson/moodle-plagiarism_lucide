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

namespace plagiarism_lucide\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Site settings of the plugin.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_settings_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'plagiarism_lucide'));
        $mform->addHelpButton('enabled', 'enabled', 'plagiarism_lucide');

        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', 'plagiarism_lucide'), ['size' => 60]);
        $mform->setType('apikey', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('apikey', 'apikey', 'plagiarism_lucide');

        $mform->addElement('advcheckbox', 'defaultenabled', get_string('defaultenabled', 'plagiarism_lucide'));
        $mform->addHelpButton('defaultenabled', 'defaultenabled', 'plagiarism_lucide');

        $mform->addElement('text', 'maxcredits', get_string('maxcredits', 'plagiarism_lucide'), ['size' => 6]);
        $mform->setType('maxcredits', PARAM_INT);
        $mform->addRule('maxcredits', null, 'numeric', null, 'client');
        $mform->addHelpButton('maxcredits', 'maxcredits', 'plagiarism_lucide');

        $mform->addElement('advcheckbox', 'deleteremote', get_string('deleteremote', 'plagiarism_lucide'));
        $mform->addHelpButton('deleteremote', 'deleteremote', 'plagiarism_lucide');

        $retention = [];
        foreach ([30, 90, 180, 365, 730] as $days) {
            $retention[$days] = get_string('numdays', 'moodle', $days);
        }
        $mform->addElement('select', 'reportretention', get_string('reportretention', 'plagiarism_lucide'), $retention);
        $mform->addHelpButton('reportretention', 'reportretention', 'plagiarism_lucide');

        $mform->addElement('textarea', 'disclosure', get_string('disclosure', 'plagiarism_lucide'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('disclosure', PARAM_TEXT);
        $mform->addHelpButton('disclosure', 'disclosure', 'plagiarism_lucide');

        $this->add_action_buttons(false);
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['enabled']) && trim((string) $data['apikey']) === '') {
            $errors['apikey'] = get_string('apikeyrequired', 'plagiarism_lucide');
        }
        if ((int) $data['maxcredits'] < 1) {
            $errors['maxcredits'] = get_string('maxcreditsinvalid', 'plagiarism_lucide');
        }
        return $errors;
    }
}
