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
 * Lists the sources of an assignment submission: its online text and each file.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_collector {

    /** Largest file accepted by the Lucide API. */
    const MAX_FILE_BYTES = 10485760;

    /** Extensions the API extracts, with the MIME type sent. */
    const SUPPORTED = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
    ];

    /**
     * Sources of a submission as Moodle stores them now.
     *
     * @param \cm_info|\stdClass $cm
     * @param \stdClass $submission assign_submission record
     * @return \stdClass[] each with sourcetype, identifier, contenthash, filename, and text or file
     */
    public static function collect($cm, \stdClass $submission): array {
        global $DB;

        $context = \context_module::instance($cm->id);
        $course = get_course($cm->course);
        $assign = new \assign($context, $cm, $course);
        $sources = [];

        $onlinetext = $assign->get_submission_plugin_by_type('onlinetext');
        if ($onlinetext && $onlinetext->is_enabled()) {
            $record = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
            if ($record && trim((string) $record->onlinetext) !== '') {
                $raw = trim($record->onlinetext);
                $sources[] = (object) [
                    'sourcetype' => 'onlinetext',
                    'identifier' => 'onlinetext',
                    'contenthash' => self::text_hash($raw),
                    'filename' => null,
                    'text' => self::to_plain_text($raw),
                ];
            }
        }

        $fileplugin = $assign->get_submission_plugin_by_type('file');
        if ($fileplugin && $fileplugin->is_enabled()) {
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files',
                $submission->id, 'filepath, filename', false);
            foreach ($files as $file) {
                $sources[] = (object) [
                    'sourcetype' => 'file',
                    'identifier' => $file->get_pathnamehash(),
                    'contenthash' => $file->get_contenthash(),
                    'filename' => $file->get_filename(),
                    'file' => $file,
                ];
            }
        }

        return $sources;
    }

    /**
     * Hash of an online text, computed the same way from the database and from get_links().
     *
     * @param string $raw
     * @return string
     */
    public static function text_hash(string $raw): string {
        return sha1(trim($raw));
    }

    /**
     * Online text HTML to plain text, keeping paragraphs and every Unicode character.
     *
     * @param string $html
     * @return string
     */
    public static function to_plain_text(string $html): string {
        $text = html_to_text($html, 0, false);
        $text = preg_replace("/[ \t]+\n/u", "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        return trim($text);
    }

    /**
     * Why a file cannot be sent, or null when it can.
     *
     * @param \stored_file $file
     * @return string|null error code
     */
    public static function file_refusal(\stored_file $file): ?string {
        if (!self::extension($file)) {
            return 'unsupported_format';
        }
        if ($file->get_filesize() > self::MAX_FILE_BYTES) {
            return 'payload_too_large';
        }
        if ($file->get_filesize() === 0) {
            return 'no_extractable_text';
        }
        return null;
    }

    /**
     * Supported extension of a file, or null.
     *
     * @param \stored_file $file
     * @return string|null
     */
    public static function extension(\stored_file $file): ?string {
        $extension = \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        return isset(self::SUPPORTED[$extension]) ? $extension : null;
    }
}
