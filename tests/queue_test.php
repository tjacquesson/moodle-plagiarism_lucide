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

use plagiarism_lucide\api\api_exception;
use plagiarism_lucide\local\queue;
use plagiarism_lucide\local\settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/fake_client.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/plagiarism/lucide/lib.php');

/**
 * Tests of the submission queue: one charge per source, retries, errors, privacy.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_lucide\local\queue
 */
final class queue_test extends \advanced_testcase {
    /** A French text long enough for the API. */
    public const TEXT = '<p>Le numérique occupe une place centrale dans notre quotidien.</p>'
        . '<p>Il transforme notre rapport au savoir — et aux autres. 😊</p>';

    /** @var \stdClass */
    protected $course;
    /** @var \stdClass */
    protected $student;
    /** @var \stdClass */
    protected $teacher;
    /** @var \cm_info|\stdClass */
    protected $cm;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableplagiarism', 1);
        set_config('enabled', 1, 'plagiarism_lucide');
        set_config('apikey', 'test-key', 'plagiarism_lucide');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $instance = $gen->create_module('assign', [
            'course' => $this->course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 2,
            'assignsubmission_file_maxsizebytes' => 1048576,
            'submissiondrafts' => 1,
        ]);
        $this->cm = get_coursemodule_from_instance('assign', $instance->id);
        settings::set_enabled_for_cm($this->cm->id, true);
    }

    /**
     * Hand in an online text as the student.
     *
     * @param string $html
     * @return \stdClass submission
     */
    protected function hand_in(string $html = self::TEXT): \stdClass {
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $this->student->id,
            'cmid' => $this->cm->id,
            'onlinetext' => $html,
        ]);
        $assign = new \assign(\context_module::instance($this->cm->id), $this->cm, $this->course);
        $this->setUser($this->student);
        $assign->submit_for_grading((object) ['userid' => $this->student->id], []);
        $this->setAdminUser();
        return $assign->get_user_submission($this->student->id, false);
    }

    /**
     * Answer of an admitted analysis.
     *
     * @param string $status
     * @return array
     */
    protected static function analysis(string $status = 'queued'): array {
        return ['id' => '11111111-1111-4111-8111-111111111111', 'object' => 'analysis', 'status' => $status,
            'word_count' => 180, 'poll_after_seconds' => 5];
    }

    /**
     * Answer of a completed report whose segments rebuild the text.
     *
     * @return array
     */
    protected static function report(): array {
        return [
            'object' => 'analysis_report',
            'analysis' => self::analysis('completed') + [
                'credits' => ['reserved' => 2, 'charged' => 2],
                'result' => [
                    'schema_version' => '1',
                    'model' => ['name' => 'lucide-v3', 'version' => null, 'engine' => 'sovereign',
                        'policy_version' => '2026-09-14'],
                    'verdict' => ['code' => 'ai_likely', 'label' => 'Probablement écrit par une IA',
                        'human_likeness_score' => 12, 'confidence' => 'standard'],
                    'coverage' => ['highlighted_ai_percent' => 60, 'precision' => 'sentence', 'reason' => null],
                    'segments' => ['available' => true, 'count' => 2],
                ],
            ],
            'analyzed_text' => "Première phrase.\nSeconde <b>phrase</b> 😊",
            'segments' => ['data' => [
                ['text' => 'Première phrase.', 'type' => 'ai', 'confidence' => 'high', 'start' => 0, 'end' => 16],
                ['text' => "\nSeconde <b>phrase</b> 😊", 'type' => 'human', 'start' => 16, 'end' => 40],
            ], 'has_more' => false],
        ];
    }

    /**
     * Rows of the source table.
     *
     * @return \stdClass[]
     */
    protected function sources(): array {
        global $DB;
        return array_values($DB->get_records('plagiarism_lucide_src', null, 'id'));
    }

    /**
     * Make every pending row due now.
     */
    protected function make_due(): void {
        global $DB;
        $DB->set_field('plagiarism_lucide_src', 'nextattempt', 0);
        unset_config('backoffsend', 'plagiarism_lucide');
        unset_config('backoffread', 'plagiarism_lucide');
    }

    public function test_hand_in_is_analysed_once_and_report_stored(): void {
        $submission = $this->hand_in();
        $this->assertEquals(1, queue::sync_due());
        $rows = $this->sources();
        $this->assertCount(1, $rows);
        $this->assertEquals(queue::STATUS_QUEUED, $rows[0]->status);
        $this->assertEquals($submission->id, $rows[0]->submissionid);

        $client = (new fake_client())
            ->will('create_analysis', self::analysis())
            ->will('get_analysis', self::analysis('completed'))
            ->will('get_report', self::report())
            ->will('delete_analysis', []);
        queue::process(50, $client);
        $this->make_due();
        queue::process(50, $client);

        $row = $this->sources()[0];
        $this->assertEquals(queue::STATUS_COMPLETED, $row->status);
        $this->assertEquals('ai_likely', $row->verdict);
        $this->assertEquals(12, $row->score);
        $this->assertEquals(60, $row->coverage);
        $this->assertEquals(2, $row->credits);
        $this->assertEquals(1, $row->remotedeleted);
        $report = json_decode($row->report, true);
        $this->assertEquals("Première phrase.\nSeconde <b>phrase</b> 😊", $report['text']);

        // The text sent is plain text, keeps its paragraphs and emoji, and carries the ceiling.
        [$key, $body] = $client->args('create_analysis')[0];
        $this->assertEquals($row->idempotencykey, $key);
        $this->assertStringContainsString('😊', $body['content']);
        $this->assertStringNotContainsString('<p>', $body['content']);
        $this->assertEquals(settings::DEFAULT_MAX_CREDITS, $body['max_credits']);
        $this->assertEquals(settings::REMOTE_RETENTION, $body['retention']);

        // Reading the same submission again queues nothing: no second charge.
        queue::request_sync($this->cm->id, $submission->id);
        queue::sync_due();
        $this->assertCount(1, $this->sources());
        $this->assertEquals(1, $client->count('create_analysis'));
    }

    public function test_lost_answer_replays_the_same_request(): void {
        $this->hand_in();
        queue::sync_due();
        $client = (new fake_client())
            ->will('create_analysis', new api_exception('network_error', 'timeout', 0, true))
            ->will('create_analysis', self::analysis());
        queue::process(50, $client);
        $row = $this->sources()[0];
        $this->assertEquals(queue::STATUS_QUEUED, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertGreaterThan(time(), (int) $row->nextattempt);

        $this->make_due();
        queue::process(50, $client);
        $calls = $client->args('create_analysis');
        $this->assertCount(2, $calls);
        $this->assertSame($calls[0], $calls[1], 'The retry must send the same key and the same body.');
        $this->assertEquals(queue::STATUS_ANALYSING, $this->sources()[0]->status);
    }

    public function test_missing_credits_block_without_charging_and_pause_sending(): void {
        $this->hand_in();
        queue::sync_due();
        $client = (new fake_client())
            ->will('create_analysis', new api_exception('insufficient_credits', 'Solde insuffisant', 402));
        queue::process(50, $client);
        $row = $this->sources()[0];
        $this->assertEquals(queue::STATUS_BLOCKED, $row->status);
        $this->assertEquals('insufficient_credits', $row->errorcode);
        $this->assertTrue(settings::is_backing_off('send'));

        // Paused: even a due row is not sent.
        global $DB;
        $DB->set_field('plagiarism_lucide_src', 'nextattempt', 0);
        queue::process(50, $client);
        $this->assertEquals(1, $client->count('create_analysis'));
    }

    public function test_refused_key_stops_all_sending(): void {
        $this->hand_in();
        queue::sync_due();
        $client = (new fake_client())
            ->will('create_analysis', new api_exception('invalid_token', 'Clé API invalide.', 401));
        queue::process(50, $client);
        $this->assertEquals('invalid_token', settings::auth_block());
        $this->assertEquals(queue::STATUS_QUEUED, $this->sources()[0]->status);

        $this->make_due();
        queue::process(50, $client);
        $this->assertEquals(1, $client->count('create_analysis'));
    }

    public function test_too_short_text_is_final(): void {
        $this->hand_in('<p>Trop court.</p>');
        queue::sync_due();
        $client = (new fake_client())
            ->will('create_analysis', new api_exception('text_too_short', 'Trop court', 422));
        queue::process(50, $client);
        $row = $this->sources()[0];
        $this->assertEquals(queue::STATUS_FAILED, $row->status);
        $this->assertEquals('text_too_short', $row->errorcode);
        $this->make_due();
        queue::process(50, $client);
        $this->assertEquals(1, $client->count('create_analysis'));
    }

    public function test_text_changed_before_sending_supersedes_old_version(): void {
        global $DB;
        $submission = $this->hand_in();
        queue::sync_due();
        $old = $this->sources()[0];

        // A teacher reverts to draft, the student edits and hands in again before the cron ran.
        $DB->set_field(
            'assignsubmission_onlinetext',
            'onlinetext',
            '<p>Nouvelle version du devoir.</p>',
            ['submission' => $submission->id]
        );
        queue::request_sync($this->cm->id, $submission->id);
        queue::sync_due();

        $rows = $this->sources();
        $this->assertCount(2, $rows);
        $this->assertEquals(queue::STATUS_SUPERSEDED, $rows[0]->status);
        $this->assertEquals(queue::STATUS_QUEUED, $rows[1]->status);
        $this->assertNotEquals($old->idempotencykey, $rows[1]->idempotencykey);

        $client = (new fake_client())->will('create_analysis', self::analysis());
        queue::process(50, $client);
        $this->assertEquals(1, $client->count('create_analysis'));
        $this->assertStringContainsString('Nouvelle version', $client->args('create_analysis')[0][1]['content']);
    }

    public function test_switched_off_activity_sends_nothing(): void {
        global $DB;
        settings::set_enabled_for_cm($this->cm->id, false);
        $this->hand_in();
        $this->assertEquals(0, $DB->count_records('plagiarism_lucide_sync'));
        $this->assertCount(0, $this->sources());
    }

    public function test_throttling_pauses_sending_but_not_reading(): void {
        global $DB;
        $this->hand_in();
        queue::sync_due();
        $client = (new fake_client())->will('create_analysis', self::analysis());
        queue::process(50, $client);

        // A second student hands in; the account queue is full.
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $other->id, 'cmid' => $this->cm->id, 'onlinetext' => '<p>Autre copie à analyser.</p>']);
        $assign = new \assign(\context_module::instance($this->cm->id), $this->cm, $this->course);
        $this->setUser($other);
        $assign->submit_for_grading((object) ['userid' => $other->id], []);
        $this->setAdminUser();
        queue::sync_due();
        $DB->set_field('plagiarism_lucide_src', 'nextattempt', 0);

        $client->will('get_analysis', self::analysis('running'))
            ->will('create_analysis', new api_exception('queue_limit_exceeded', 'File pleine', 429, true, 5));
        queue::process(50, $client);
        $this->assertTrue(settings::is_backing_off('send'));
        $this->assertFalse(settings::is_backing_off('read'));
        $this->assertEquals(1, $client->count('get_analysis'));
    }

    public function test_privacy_deletion_erases_locally_and_queues_remote_erasure(): void {
        global $DB;
        $this->hand_in();
        queue::sync_due();
        $row = $this->sources()[0];
        $DB->update_record('plagiarism_lucide_src', (object) ['id' => $row->id, 'status' => queue::STATUS_COMPLETED,
            'remoteid' => '22222222-2222-4222-8222-222222222222', 'remotedeleted' => 0, 'report' => '{"text":"x"}']);

        $context = \context_module::instance($this->cm->id);
        \plagiarism_lucide\privacy\provider::delete_plagiarism_for_user($this->student->id, $context);

        $this->assertCount(0, $this->sources());
        $this->assertTrue($DB->record_exists('plagiarism_lucide_del', ['remoteid' => '22222222-2222-4222-8222-222222222222']));

        $client = (new fake_client())->will('delete_analysis', []);
        $this->assertEquals(1, queue::process_remote_deletions($client));
        $this->assertEquals(0, $DB->count_records('plagiarism_lucide_del'));
    }

    public function test_badge_is_for_teachers_only(): void {
        $this->hand_in();
        queue::sync_due();
        $plugin = new \plagiarism_plugin_lucide();
        $links = ['cmid' => $this->cm->id, 'userid' => $this->student->id, 'content' => trim(self::TEXT),
            'assignment' => $this->cm->instance];

        $this->setUser($this->student);
        $this->assertSame('', $plugin->get_links($links));

        $this->setUser($this->teacher);
        $html = $plugin->get_links($links);
        $this->assertStringContainsString('plagiarism-lucide', $html);
        $this->assertStringContainsString(get_string('status_pending', 'plagiarism_lucide'), $html);
    }

    public function test_highlighting_escapes_and_rebuilds_text(): void {
        $report = self::report();
        $payload = [
            'text' => $report['analyzed_text'],
            'segments' => $report['segments']['data'],
            'segments_available' => true,
        ];
        $html = \plagiarism_lucide\output\presenter::highlighted_text($payload);
        $this->assertStringContainsString('&lt;b&gt;phrase&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('plagiarism-lucide-ai--high', $html);
        $this->assertStringContainsString('😊', $html);

        // Segments that do not rebuild the text are ignored rather than trusted.
        $payload['segments'][0]['text'] = 'Autre chose.';
        $this->assertStringNotContainsString('<mark', \plagiarism_lucide\output\presenter::highlighted_text($payload));
    }
}
