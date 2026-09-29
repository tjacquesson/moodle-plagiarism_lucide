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

use plagiarism_lucide\api\api_exception;
use plagiarism_lucide\api\client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Life cycle of the analysed sources, from the submission to the stored report.
 *
 * queued -> (file only: extracting) -> analysing -> completed
 * Side exits: blocked (credits, retried), failed (final), unsupported (never sent),
 * superseded (replaced before being sent), expired (local report purged).
 *
 * Each source carries an idempotency key created before the first call, so a
 * retry after a lost answer finds the same analysis and is never charged twice.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue {

    const STATUS_QUEUED = 'queued';
    const STATUS_EXTRACTING = 'extracting';
    const STATUS_ANALYSING = 'analysing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_BLOCKED = 'blocked';
    const STATUS_FAILED = 'failed';
    const STATUS_UNSUPPORTED = 'unsupported';
    const STATUS_SUPERSEDED = 'superseded';
    const STATUS_EXPIRED = 'expired';

    /** Statuses that still need a call to Lucide. */
    const ACTIVE = [self::STATUS_QUEUED, self::STATUS_BLOCKED, self::STATUS_EXTRACTING, self::STATUS_ANALYSING];

    /** Give up sending a source after this long without admission. */
    const DEADLINE = DAYSECS;

    /** Delays between technical retries, in seconds. */
    const BACKOFF = [60, 120, 300, 900, 1800, 3600];

    /** Grouping delay after an editable submission, to absorb quick successive saves. */
    const DEBOUNCE = 60;

    /**
     * Ask for a submission to be read and its sources queued.
     *
     * @param int $cmid
     * @param int $submissionid
     * @param int $delay seconds
     * @param int $requestedby user id of a manual request, 0 when automatic
     */
    public static function request_sync(int $cmid, int $submissionid, int $delay = 0, int $requestedby = 0): void {
        global $DB;
        $now = time();
        $existing = $DB->get_record('plagiarism_lucide_sync', ['submissionid' => $submissionid]);
        if ($existing) {
            $existing->cmid = $cmid;
            $existing->notbefore = $now + $delay;
            $existing->requestedby = $requestedby ?: $existing->requestedby;
            $DB->update_record('plagiarism_lucide_sync', $existing);
            return;
        }
        try {
            $DB->insert_record('plagiarism_lucide_sync', (object) [
                'cmid' => $cmid,
                'submissionid' => $submissionid,
                'requestedby' => $requestedby,
                'notbefore' => $now + $delay,
                'timecreated' => $now,
            ]);
        } catch (\dml_write_exception $e) {
            // Another request inserted the same submission at the same moment: one row is enough.
            return;
        }
    }

    /**
     * Queue every submitted work of an activity that has no analysis yet.
     *
     * @param int $cmid
     * @param int $requestedby
     * @return int number of submissions queued
     */
    public static function request_existing(int $cmid, int $requestedby): int {
        $count = 0;
        foreach (self::unanalysed_submissions($cmid) as $submissionid) {
            self::request_sync($cmid, $submissionid, 0, $requestedby);
            $count++;
        }
        return $count;
    }

    /**
     * Latest submitted works of an activity without any Lucide source yet.
     *
     * @param int $cmid
     * @return int[] submission ids
     */
    public static function unanalysed_submissions(int $cmid): array {
        global $DB;
        $cm = get_coursemodule_from_id('assign', $cmid);
        if (!$cm) {
            return [];
        }
        $sql = "SELECT s.id
                  FROM {assign_submission} s
             LEFT JOIN {plagiarism_lucide_src} l ON l.submissionid = s.id
                 WHERE s.assignment = :assignment AND s.status = :status AND s.latest = 1 AND l.id IS NULL
              ORDER BY s.id";
        return array_map('intval', $DB->get_fieldset_sql($sql, [
            'assignment' => $cm->instance,
            'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
        ]));
    }

    /**
     * Read the submissions whose grouping delay is over.
     *
     * @param int $limit
     * @return int submissions read
     */
    public static function sync_due(int $limit = 50): int {
        global $DB;
        $rows = $DB->get_records_select('plagiarism_lucide_sync', 'notbefore <= ?', [time()],
            'notbefore ASC, id ASC', '*', 0, $limit);
        foreach ($rows as $row) {
            try {
                self::sync_submission((int) $row->cmid, (int) $row->submissionid);
            } catch (\Throwable $e) {
                mtrace('plagiarism_lucide: submission ' . $row->submissionid . ' could not be read: ' . $e->getMessage());
            }
            // A newer save during the read moved notbefore: keep that request for the next run.
            $DB->delete_records('plagiarism_lucide_sync', ['id' => $row->id, 'notbefore' => $row->notbefore]);
        }
        return count($rows);
    }

    /**
     * Turn the current state of a submission into sources to analyse.
     *
     * A source already known with the same content is never queued again, so
     * re-reading a submission costs nothing.
     *
     * @param int $cmid
     * @param int $submissionid
     * @return int sources created
     */
    public static function sync_submission(int $cmid, int $submissionid): int {
        global $DB;

        if (!settings::is_enabled_for_cm($cmid)) {
            return 0;
        }
        $cm = get_coursemodule_from_id('assign', $cmid);
        if (!$cm) {
            return 0;
        }
        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission || (int) $submission->assignment !== (int) $cm->instance) {
            return 0;
        }
        if ($submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED || empty($submission->latest)) {
            return 0;
        }

        $now = time();
        $created = 0;
        $current = [];
        foreach (submission_collector::collect($cm, $submission) as $source) {
            $current[$source->identifier] = $source->contenthash;
            $known = $DB->record_exists('plagiarism_lucide_src', [
                'submissionid' => $submissionid,
                'identifier' => $source->identifier,
                'contenthash' => $source->contenthash,
            ]);
            if ($known) {
                continue;
            }
            $status = self::STATUS_QUEUED;
            $errorcode = null;
            if ($source->sourcetype === 'file') {
                $errorcode = submission_collector::file_refusal($source->file);
                if ($errorcode) {
                    $status = self::STATUS_UNSUPPORTED;
                }
            }
            try {
                $DB->insert_record('plagiarism_lucide_src', (object) [
                    'cmid' => $cmid,
                    'submissionid' => $submissionid,
                    'userid' => (int) $submission->userid,
                    'groupid' => (int) $submission->groupid,
                    'sourcetype' => $source->sourcetype,
                    'identifier' => $source->identifier,
                    'contenthash' => $source->contenthash,
                    'filename' => $source->filename ? \core_text::substr($source->filename, 0, 255) : null,
                    'status' => $status,
                    'idempotencykey' => \core\uuid::generate(),
                    'maxcredits' => settings::max_credits(),
                    'attempts' => 0,
                    'nextattempt' => $now,
                    'errorcode' => $errorcode,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $created++;
            } catch (\dml_write_exception $e) {
                // Created meanwhile by a concurrent run.
                continue;
            }
        }

        // Versions replaced before being sent are dropped: they cost nothing.
        $waiting = $DB->get_records_select('plagiarism_lucide_src', 'submissionid = ? AND status IN (?, ?)',
            [$submissionid, self::STATUS_QUEUED, self::STATUS_BLOCKED]);
        foreach ($waiting as $row) {
            if (($current[$row->identifier] ?? null) !== $row->contenthash) {
                self::save($row, ['status' => self::STATUS_SUPERSEDED]);
            }
        }

        return $created;
    }

    /**
     * Advance every source that is due, within a time budget.
     *
     * @param int $budget seconds
     * @param client|null $client
     * @return array counters
     */
    public static function process(int $budget = 50, ?client $client = null): array {
        global $DB;

        $stats = ['sent' => 0, 'completed' => 0, 'failed' => 0, 'waiting' => 0];
        if (!settings::is_configured() || settings::auth_block() !== '') {
            return $stats;
        }
        $client = $client ?? new client();
        $stop = time() + $budget;

        [$insql, $params] = $DB->get_in_or_equal(self::ACTIVE, SQL_PARAMS_NAMED);
        $params['now'] = time();
        $rows = $DB->get_records_select('plagiarism_lucide_src', "status $insql AND nextattempt <= :now", $params,
            'nextattempt ASC, id ASC', '*', 0, 100);

        foreach ($rows as $row) {
            if (time() >= $stop || settings::auth_block() !== '') {
                break;
            }
            $sending = $row->status !== self::STATUS_ANALYSING;
            if (settings::is_backing_off($sending ? 'send' : 'read')) {
                continue;
            }
            if ($sending && !settings::is_enabled_for_cm((int) $row->cmid)) {
                // Switched off after the submission: keep it, look again later.
                self::save($row, ['nextattempt' => time() + HOURSECS]);
                continue;
            }
            $before = $row->status;
            try {
                self::advance($client, $row);
            } catch (api_exception $e) {
                self::handle_error($row, $e);
            } catch (\Throwable $e) {
                mtrace('plagiarism_lucide: source ' . $row->id . ' unexpected error: ' . $e->getMessage());
                self::retry_later($row, 'internal_error');
            }
            if ($row->status === self::STATUS_COMPLETED) {
                $stats['completed']++;
            } else if (in_array($row->status, [self::STATUS_FAILED, self::STATUS_UNSUPPORTED], true)) {
                $stats['failed']++;
            } else if ($before !== $row->status && $row->status === self::STATUS_ANALYSING) {
                $stats['sent']++;
            } else {
                $stats['waiting']++;
            }
        }
        if ($rows) {
            set_config('lastcontact', time(), 'plagiarism_lucide');
        }
        return $stats;
    }

    /**
     * Take the next step for one source.
     *
     * @param client $client
     * @param \stdClass $row
     */
    protected static function advance(client $client, \stdClass $row): void {
        switch ($row->status) {
            case self::STATUS_QUEUED:
            case self::STATUS_BLOCKED:
                self::send($client, $row);
                break;
            case self::STATUS_EXTRACTING:
                self::check_extraction($client, $row);
                break;
            case self::STATUS_ANALYSING:
                self::poll($client, $row);
                break;
        }
    }

    /**
     * Send an online text, or upload a file for extraction.
     *
     * @param client $client
     * @param \stdClass $row
     */
    protected static function send(client $client, \stdClass $row): void {
        global $DB;

        if ($row->sourcetype === 'onlinetext') {
            $raw = $DB->get_field('assignsubmission_onlinetext', 'onlinetext', ['submission' => $row->submissionid]);
            if ($raw === false || submission_collector::text_hash((string) $raw) !== $row->contenthash) {
                self::save($row, ['status' => self::STATUS_SUPERSEDED]);
                return;
            }
            $text = submission_collector::to_plain_text((string) $raw);
            self::admitted($row, $client->create_analysis($row->idempotencykey, self::body($row, ['content' => $text])));
            return;
        }

        if (!empty($row->remotefileid)) {
            // Already uploaded: resume from the extraction.
            self::save($row, ['status' => self::STATUS_EXTRACTING]);
            self::check_extraction($client, $row);
            return;
        }

        $file = get_file_storage()->get_file_by_hash($row->identifier);
        if (!$file || $file->get_contenthash() !== $row->contenthash) {
            self::save($row, ['status' => self::STATUS_SUPERSEDED]);
            return;
        }
        $extension = submission_collector::extension($file);
        if (!$extension) {
            self::save($row, ['status' => self::STATUS_UNSUPPORTED, 'errorcode' => 'unsupported_format']);
            return;
        }
        $tmp = $file->copy_content_to_temp('plagiarism_lucide');
        try {
            // Neutral name: the original may contain the student's name.
            $remote = $client->upload_file($tmp, submission_collector::SUPPORTED[$extension], 'submission.' . $extension);
        } finally {
            @unlink($tmp);
        }
        self::save($row, [
            'status' => self::STATUS_EXTRACTING,
            'remotefileid' => (string) $remote['id'],
            'errorcode' => null,
            'nextattempt' => time() + max(5, (int) ($remote['poll_after_seconds'] ?? 10)),
        ]);
    }

    /**
     * Check an uploaded file, and admit its analysis once the text is extracted.
     *
     * @param client $client
     * @param \stdClass $row
     */
    protected static function check_extraction(client $client, \stdClass $row): void {
        $file = $client->get_file($row->remotefileid);
        switch ($file['status'] ?? '') {
            case 'ready':
                $body = self::body($row, ['file_id' => $row->remotefileid]);
                self::admitted($row, $client->create_analysis($row->idempotencykey, $body));
                return;
            case 'failed':
                self::fail($row, (string) ($file['error']['code'] ?? 'extraction_failed'));
                self::quietly(fn() => $client->delete_file($row->remotefileid));
                return;
            default:
                self::save($row, [
                    'status' => self::STATUS_EXTRACTING,
                    'nextattempt' => time() + max(10, min(300, (int) ($file['poll_after_seconds'] ?? 30))),
                ]);
        }
    }

    /**
     * Follow an admitted analysis until it ends.
     *
     * @param client $client
     * @param \stdClass $row
     */
    protected static function poll(client $client, \stdClass $row): void {
        $analysis = $client->get_analysis($row->remoteid);
        switch ($analysis['status'] ?? '') {
            case 'completed':
                self::store_report($client, $row, $client->get_report($row->remoteid));
                return;
            case 'failed':
            case 'cancelled':
                self::fail($row, (string) ($analysis['error']['code'] ?? 'analysis_failed'));
                return;
            default:
                self::save($row, [
                    'nextattempt' => time() + max(20, min(300, (int) ($analysis['poll_after_seconds'] ?? 30))),
                ]);
        }
    }

    /**
     * Record an admitted analysis.
     *
     * @param \stdClass $row
     * @param array $analysis
     */
    protected static function admitted(\stdClass $row, array $analysis): void {
        $done = in_array($analysis['status'] ?? '', ['completed', 'failed', 'cancelled'], true);
        self::save($row, [
            'status' => self::STATUS_ANALYSING,
            'remoteid' => (string) $analysis['id'],
            'wordcount' => isset($analysis['word_count']) ? (int) $analysis['word_count'] : null,
            'errorcode' => null,
            'attempts' => 0,
            'nextattempt' => $done ? time() : time() + max(20, (int) ($analysis['poll_after_seconds'] ?? 30)),
        ]);
    }

    /**
     * Keep the report in Moodle, then erase the copy at Lucide.
     *
     * @param client $client
     * @param \stdClass $row
     * @param array $report
     */
    protected static function store_report(client $client, \stdClass $row, array $report): void {
        $analysis = $report['analysis'] ?? [];
        $result = $analysis['result'] ?? null;
        if (!is_array($result) || empty($result['verdict']['code'])) {
            self::fail($row, 'report_unavailable');
            return;
        }

        $segments = [];
        foreach ($report['segments']['data'] ?? [] as $segment) {
            $segments[] = [
                'text' => (string) ($segment['text'] ?? ''),
                'type' => (string) ($segment['type'] ?? 'human'),
                'confidence' => $segment['confidence'] ?? null,
            ];
        }
        $payload = [
            'schema' => 1,
            'text' => (string) ($report['analyzed_text'] ?? ''),
            'segments' => $segments,
            'segments_available' => (bool) ($result['segments']['available'] ?? false),
            'model' => $result['model']['name'] ?? null,
            'policy' => $result['model']['policy_version'] ?? null,
        ];

        $now = time();
        self::save($row, [
            'status' => self::STATUS_COMPLETED,
            'verdict' => (string) $result['verdict']['code'],
            'verdictlabel' => \core_text::substr((string) ($result['verdict']['label'] ?? ''), 0, 255),
            'score' => isset($result['verdict']['human_likeness_score']) ? (int) $result['verdict']['human_likeness_score'] : null,
            'confidence' => $result['verdict']['confidence'] ?? null,
            'coverage' => isset($result['coverage']['highlighted_ai_percent'])
                ? (int) $result['coverage']['highlighted_ai_percent'] : null,
            'coverageprecision' => $result['coverage']['precision'] ?? null,
            'coveragereason' => $result['coverage']['reason'] ?? null,
            'wordcount' => isset($analysis['word_count']) ? (int) $analysis['word_count'] : $row->wordcount,
            'credits' => isset($analysis['credits']['charged']) ? (int) $analysis['credits']['charged'] : null,
            'report' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'errorcode' => null,
            'timecompleted' => $now,
            'expiresat' => $now + settings::report_retention(),
        ]);

        if (settings::delete_remote_after_fetch()) {
            try {
                $client->delete_analysis($row->remoteid);
                self::save($row, ['remotedeleted' => 1]);
                settings::note_delete_right(true);
            } catch (api_exception $e) {
                if ($e->apicode === 'insufficient_scope') {
                    settings::note_delete_right(false);
                }
                self::schedule_remote_delete($row->remoteid);
            }
        }
        if (!empty($row->remotefileid)) {
            self::quietly(fn() => $client->delete_file($row->remotefileid));
        }
    }

    /**
     * Decide what an API error means for this source.
     *
     * @param \stdClass $row
     * @param api_exception $e
     */
    protected static function handle_error(\stdClass $row, api_exception $e): void {
        $sending = $row->status !== self::STATUS_ANALYSING;
        switch ($e->kind()) {
            case api_exception::KIND_AUTH:
                settings::block_auth($e->apicode);
                return;
            case api_exception::KIND_THROTTLE:
                settings::back_off($sending ? 'send' : 'read', $e->retryafter ?: 60);
                return;
            case api_exception::KIND_CREDITS:
                // The balance is shared by the whole account: pause every sending, not only this one.
                $wait = $e->apicode === 'credit_replenishment_pending' ? 120 : 900;
                settings::back_off('send', $wait);
                self::save($row, ['status' => self::STATUS_BLOCKED, 'errorcode' => $e->apicode,
                    'nextattempt' => time() + $wait]);
                return;
            case api_exception::KIND_GONE:
                if ($e->apicode === 'file_expired' || ($row->status === self::STATUS_EXTRACTING && $e->httpstatus === 404)) {
                    // The upload expired before admission: upload it again.
                    self::save($row, ['status' => self::STATUS_QUEUED, 'remotefileid' => null, 'nextattempt' => time()]);
                    return;
                }
                self::fail($row, $row->status === self::STATUS_ANALYSING ? 'report_unavailable' : $e->apicode);
                return;
            case api_exception::KIND_CONTENT:
                self::fail($row, $e->apicode);
                return;
            default:
                self::retry_later($row, $e->apicode);
        }
    }

    /**
     * Schedule a technical retry, or give up after the deadline.
     *
     * @param \stdClass $row
     * @param string $code
     */
    protected static function retry_later(\stdClass $row, string $code): void {
        $attempts = (int) $row->attempts + 1;
        if ($row->status !== self::STATUS_ANALYSING && time() - (int) $row->timecreated > self::DEADLINE) {
            self::fail($row, 'timeout');
            return;
        }
        $delay = self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)];
        self::save($row, [
            'attempts' => $attempts,
            'errorcode' => \core_text::substr($code, 0, 64),
            'nextattempt' => time() + $delay + random_int(0, 30),
        ]);
    }

    /**
     * Mark a source as finally not analysed.
     *
     * @param \stdClass $row
     * @param string $code
     */
    protected static function fail(\stdClass $row, string $code): void {
        self::save($row, ['status' => self::STATUS_FAILED, 'errorcode' => \core_text::substr($code, 0, 64)]);
    }

    /**
     * Body of an analysis request. Every field is frozen on the row, so a retry
     * sends exactly the same request and the idempotency key replays.
     *
     * @param \stdClass $row
     * @param array $input content or file_id
     * @return array
     */
    protected static function body(\stdClass $row, array $input): array {
        return $input + [
            'max_credits' => (int) $row->maxcredits,
            'title' => 'Moodle ' . $row->cmid . '-' . $row->submissionid . '-' . $row->id,
            'retention' => settings::REMOTE_RETENTION,
        ];
    }

    /**
     * Update fields of a source, in memory and in the database.
     *
     * @param \stdClass $row
     * @param array $fields
     */
    protected static function save(\stdClass $row, array $fields): void {
        global $DB;
        foreach ($fields as $name => $value) {
            $row->$name = $value;
        }
        $row->timemodified = time();
        $DB->update_record('plagiarism_lucide_src', $row);
    }

    /**
     * Remember a remote analysis to erase later.
     *
     * @param string $remoteid
     */
    public static function schedule_remote_delete(string $remoteid): void {
        global $DB;
        if ($DB->record_exists('plagiarism_lucide_del', ['remoteid' => $remoteid])) {
            return;
        }
        try {
            $DB->insert_record('plagiarism_lucide_del', (object) [
                'remoteid' => $remoteid,
                'attempts' => 0,
                'nextattempt' => time(),
                'timecreated' => time(),
            ]);
        } catch (\dml_write_exception $e) {
            return;
        }
    }

    /**
     * Delete sources in Moodle at once, and erase what Lucide may still hold.
     *
     * @param \stdClass[] $rows
     */
    public static function delete_sources(array $rows): void {
        global $DB;
        foreach ($rows as $row) {
            if (!empty($row->remoteid) && empty($row->remotedeleted)) {
                self::schedule_remote_delete($row->remoteid);
            }
            $DB->delete_records('plagiarism_lucide_src', ['id' => $row->id]);
        }
    }

    /**
     * Retry pending remote deletions.
     *
     * @param client|null $client
     * @return int confirmed deletions
     */
    public static function process_remote_deletions(?client $client = null): int {
        global $DB;
        if (!settings::is_configured() && trim((string) settings::get('apikey', '')) === '') {
            return 0;
        }
        $client = $client ?? new client();
        $done = 0;
        $rows = $DB->get_records_select('plagiarism_lucide_del', 'nextattempt <= ?', [time()], 'id', '*', 0, 200);
        foreach ($rows as $row) {
            try {
                $client->delete_analysis($row->remoteid);
                $DB->delete_records('plagiarism_lucide_del', ['id' => $row->id]);
                $DB->set_field('plagiarism_lucide_src', 'remotedeleted', 1, ['remoteid' => $row->remoteid]);
                settings::note_delete_right(true);
                $done++;
            } catch (api_exception $e) {
                if ($e->apicode === 'insufficient_scope') {
                    settings::note_delete_right(false);
                }
                if ($e->kind() === api_exception::KIND_AUTH) {
                    // A revoked key cannot erase anything; the remote retention still applies.
                    break;
                }
                $row->attempts++;
                $row->nextattempt = time() + self::BACKOFF[min($row->attempts - 1, count(self::BACKOFF) - 1)];
                $DB->update_record('plagiarism_lucide_del', $row);
            }
        }
        return $done;
    }

    /**
     * Purge expired reports and old bookkeeping.
     *
     * @return int reports purged
     */
    public static function purge_expired(): int {
        global $DB;
        $now = time();
        $expired = $DB->get_records_select('plagiarism_lucide_src', 'status = ? AND expiresat IS NOT NULL AND expiresat < ?',
            [self::STATUS_COMPLETED, $now], 'id', 'id');
        foreach ($expired as $row) {
            $DB->update_record('plagiarism_lucide_src', (object) [
                'id' => $row->id,
                'status' => self::STATUS_EXPIRED,
                'report' => null,
                'verdict' => null,
                'verdictlabel' => null,
                'score' => null,
                'confidence' => null,
                'coverage' => null,
                'coverageprecision' => null,
                'coveragereason' => null,
                'timemodified' => $now,
            ]);
        }
        // Rows that never produced a report are kept as long as a report would have been.
        $DB->delete_records_select('plagiarism_lucide_src', 'status IN (?, ?, ?) AND timemodified < ?',
            [self::STATUS_SUPERSEDED, self::STATUS_FAILED, self::STATUS_UNSUPPORTED, $now - settings::report_retention()]);
        $DB->delete_records_select('plagiarism_lucide_sync', 'timecreated < ?', [$now - WEEKSECS]);
        return count($expired);
    }

    /**
     * Run a clean-up call whose failure must not change the outcome.
     *
     * @param callable $call
     */
    protected static function quietly(callable $call): void {
        try {
            $call();
        } catch (\Throwable $e) {
            debugging('plagiarism_lucide: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
