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

namespace plagiarism_lucide\output;

use html_writer;
use moodle_url;
use plagiarism_lucide\local\queue;

/**
 * HTML of the status badge and of the report.
 *
 * Every text coming from Lucide or from the submission is escaped here; the
 * highlighting is built by Moodle from plain segments, never from remote HTML.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class presenter {

    /** Error codes with their own explanation string. */
    const KNOWN_ERRORS = [
        'text_too_short', 'text_too_long', 'extracted_text_too_large', 'no_extractable_text', 'unsupported_format',
        'payload_too_large', 'file_rejected', 'timeout', 'insufficient_credits', 'credit_budget_exceeded',
        'credit_replenishment_pending', 'report_unavailable', 'engine_unavailable',
    ];

    /**
     * Short verdict or status shown next to a file or an online text.
     *
     * @param \stdClass|null $row source, null when not analysed
     * @param moodle_url|null $scanurl manual analysis action, when allowed
     * @return string HTML
     */
    public static function badge(?\stdClass $row, ?moodle_url $scanurl = null): string {
        $brand = html_writer::span('Lucide', 'plagiarism-lucide-brand');

        if (!$row) {
            if (!$scanurl) {
                return '';
            }
            $button = html_writer::link($scanurl, get_string('analysenow', 'plagiarism_lucide'),
                ['class' => 'plagiarism-lucide-action']);
            return html_writer::div($brand . ' ' . html_writer::span(get_string('status_none', 'plagiarism_lucide'),
                'plagiarism-lucide-badge plagiarism-lucide-badge--muted') . ' ' . $button, 'plagiarism-lucide');
        }

        switch ($row->status) {
            case queue::STATUS_COMPLETED:
                $label = self::verdict_label($row, true);
                $class = 'plagiarism-lucide-badge--' . self::verdict_class($row->verdict);
                $extra = '';
                if ($row->coverage !== null && $row->coverageprecision !== 'none' && (int) $row->coverage > 0) {
                    $extra = ' ' . html_writer::span(get_string('coverage_short', 'plagiarism_lucide', (int) $row->coverage),
                        'plagiarism-lucide-coverage');
                }
                $link = html_writer::link(new moodle_url('/plagiarism/lucide/report.php', ['id' => $row->id]),
                    get_string('viewreport', 'plagiarism_lucide'), ['class' => 'plagiarism-lucide-action']);
                return html_writer::div($brand . ' ' . html_writer::span($label, 'plagiarism-lucide-badge ' . $class)
                    . $extra . ' ' . $link, 'plagiarism-lucide');

            case queue::STATUS_QUEUED:
            case queue::STATUS_EXTRACTING:
            case queue::STATUS_ANALYSING:
                $text = get_string('status_pending', 'plagiarism_lucide');
                $class = 'plagiarism-lucide-badge--muted';
                break;
            case queue::STATUS_BLOCKED:
                $text = get_string('status_blocked', 'plagiarism_lucide');
                $class = 'plagiarism-lucide-badge--warning';
                break;
            case queue::STATUS_EXPIRED:
                $text = get_string('status_expired', 'plagiarism_lucide');
                $class = 'plagiarism-lucide-badge--muted';
                break;
            default:
                $text = get_string('status_failed', 'plagiarism_lucide') . ' — ' . self::error_reason($row->errorcode);
                $class = 'plagiarism-lucide-badge--muted';
        }
        return html_writer::div($brand . ' ' . html_writer::span($text, 'plagiarism-lucide-badge ' . $class),
            'plagiarism-lucide');
    }

    /**
     * Verdict wording, on the same five steps as the Lucide application.
     *
     * The step comes from the decision score; the colour comes from the verdict code.
     *
     * @param \stdClass $row completed source
     * @param bool $short badge wording instead of the report title
     * @return string
     */
    public static function verdict_label(\stdClass $row, bool $short = false): string {
        $prefix = $short ? 'badge_' : 'verdict_';
        if ($row->score === null || $row->score === '') {
            $fallback = ['ai_likely' => 'ai', 'uncertain' => 'maybe', 'human_likely' => 'human'][$row->verdict] ?? 'unknown';
            return get_string($prefix . $fallback, 'plagiarism_lucide');
        }
        $score = (int) $row->score;
        if ($score >= 86) {
            $step = 'veryhuman';
        } else if ($score >= 66) {
            $step = 'human';
        } else if ($score >= 50) {
            $step = 'maybe';
        } else if ($score >= 20) {
            $step = 'ai';
        } else {
            $step = 'veryai';
        }
        return get_string($prefix . $step, 'plagiarism_lucide');
    }

    /**
     * CSS modifier of a verdict.
     *
     * @param string|null $code
     * @return string
     */
    public static function verdict_class(?string $code): string {
        return ['ai_likely' => 'ai', 'uncertain' => 'uncertain', 'human_likely' => 'human'][$code] ?? 'muted';
    }

    /**
     * Why a source was not analysed.
     *
     * @param string|null $code
     * @return string
     */
    public static function error_reason(?string $code): string {
        if ($code && in_array($code, self::KNOWN_ERRORS, true)) {
            return get_string('error_' . $code, 'plagiarism_lucide');
        }
        return get_string('error_other', 'plagiarism_lucide');
    }

    /**
     * The analysed text with the passages that read as AI highlighted.
     *
     * Falls back to the plain text whenever the segments do not rebuild the text exactly.
     *
     * @param array $report stored report payload
     * @return string HTML
     */
    public static function highlighted_text(array $report): string {
        $text = (string) ($report['text'] ?? '');
        $segments = $report['segments'] ?? [];
        $rebuilt = implode('', array_map(fn($s) => (string) ($s['text'] ?? ''), $segments));

        if (empty($report['segments_available']) || !$segments || $rebuilt !== $text) {
            return html_writer::div(self::paragraphs($text), 'plagiarism-lucide-text');
        }

        $html = '';
        foreach ($segments as $segment) {
            $piece = self::paragraphs((string) $segment['text']);
            if (($segment['type'] ?? '') === 'ai') {
                $level = ($segment['confidence'] ?? '') === 'high' ? 'high' : 'medium';
                $html .= html_writer::tag('mark', $piece, ['class' => 'plagiarism-lucide-ai plagiarism-lucide-ai--' . $level]);
            } else {
                $html .= $piece;
            }
        }
        return html_writer::div($html, 'plagiarism-lucide-text');
    }

    /**
     * Escape a text and keep its line breaks.
     *
     * @param string $text
     * @return string
     */
    protected static function paragraphs(string $text): string {
        return nl2br(s($text), false);
    }
}
