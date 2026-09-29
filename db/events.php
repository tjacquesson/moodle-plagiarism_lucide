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
 * Event observers for plagiarism_lucide.
 *
 * Observers only write local rows. Every network call happens in scheduled tasks,
 * so a Lucide outage can never block a student submission.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => '\plagiarism_lucide\observer::assessable_submitted',
    ],
    [
        'eventname' => '\mod_assign\event\submission_removed',
        'callback' => '\plagiarism_lucide\observer::submission_removed',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\plagiarism_lucide\observer::course_module_deleted',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\plagiarism_lucide\observer::user_deleted',
    ],
];
