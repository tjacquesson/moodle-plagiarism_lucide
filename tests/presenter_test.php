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

namespace plagiarism_lucide;

use plagiarism_lucide\output\presenter;

/**
 * Tests of the verdict wording: the same steps as the Lucide application.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_lucide\output\presenter
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\plagiarism_lucide\output\presenter::class)]
final class presenter_test extends \basic_testcase {
    /**
     * A completed source as stored by the queue.
     *
     * @param string $code verdict code
     * @param string|null $label label sent by Lucide
     * @param int|null $score human likeness score
     * @return \stdClass
     */
    private static function row(string $code, ?string $label, ?int $score): \stdClass {
        return (object) ['verdict' => $code, 'verdictlabel' => $label, 'score' => $score];
    }

    public function test_lucide_3_labels_give_the_application_steps(): void {
        $cases = [
            ['human_likely', 'Human', 100, 'veryhuman'],
            ['human_likely', 'Likely human', 80, 'human'],
            ['uncertain', 'Mixed', 74, 'mixed'],
            ['ai_likely', 'Mostly AI', 30, 'mostlyai'],
            ['ai_likely', 'Written by AI', 9, 'writtenai'],
        ];
        foreach ($cases as [$code, $label, $score, $step]) {
            $row = self::row($code, $label, $score);
            $this->assertSame(get_string('verdict_' . $step, 'plagiarism_lucide'), presenter::verdict_label($row), $label);
            $this->assertSame(get_string('badge_' . $step, 'plagiarism_lucide'), presenter::verdict_label($row, true), $label);
        }
    }

    public function test_older_model_labels_keep_their_steps(): void {
        $this->assertSame(get_string('verdict_veryhuman', 'plagiarism_lucide'),
            presenter::verdict_label(self::row('human_likely', 'Very likely human', 92)));
        $this->assertSame(get_string('verdict_human', 'plagiarism_lucide'),
            presenter::verdict_label(self::row('human_likely', 'Likely human', 70)));
        $this->assertSame(get_string('verdict_maybe', 'plagiarism_lucide'),
            presenter::verdict_label(self::row('uncertain', 'Possibly written by AI', 55)));
        $this->assertSame(get_string('verdict_veryai', 'plagiarism_lucide'),
            presenter::verdict_label(self::row('ai_likely', 'Very likely written by AI', 10)));
    }

    public function test_unknown_label_follows_the_verdict_code_never_the_score_alone(): void {
        // A Lucide 3.0 mixed text at 74 with a label this version does not know: the code
        // says uncertain, so the wording must not say human like the old score bounds would.
        $this->assertSame(get_string('verdict_maybe', 'plagiarism_lucide'),
            presenter::verdict_label(self::row('uncertain', 'Something new', 74)));
        // French label stored before the API switched to English.
        $this->assertSame(get_string('verdict_veryhuman', 'plagiarism_lucide'),
            presenter::verdict_label(self::row('human_likely', 'Très probablement humain', 92)));
        $this->assertSame(get_string('verdict_veryai', 'plagiarism_lucide'),
            presenter::verdict_label(self::row('ai_likely', null, 12)));
    }
}
