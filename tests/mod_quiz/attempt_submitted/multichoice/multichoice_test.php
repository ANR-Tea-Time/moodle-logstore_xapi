<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace logstore_xapi\mod_quiz\attempt_submitted\multichoice;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/admin/tool/log/store/xapi/tests/xapi_test_case.php');

/**
 * Unit test for mod_quiz attempt submitted (multichoice) event.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multichoice_test extends \logstore_xapi\xapi_test_case {

    /**
     * Retrieve the directory of the unit test.
     *
     * @return string
     */
    protected function get_test_dir() {
        return __DIR__;
    }

    /**
     * Retrieve the plugin type being tested.
     *
     * @return string
     */
    protected function get_plugin_type() {
        return "mod";
    }

    /**
     * Retrieve the plugin name being tested.
     *
     * @return string
     */
    protected function get_plugin_name() {
        return "quiz";
    }

    /**
     * Appease auto-detecting of test cases. xapi_test_case has default test cases.
     *
     * @covers ::attempt_submitted
     * @return void
     */
    public function test_init() {
    }

    /**
     * Ensure a matching quiz interaction row upgrades the submitted-attempt result.
     *
     * @return void
     */
    public function test_attempt_submitted_enriches_quiz_interactions() {
        $event = $this->get_event();
        $testdata = $this->get_test_data();
        $testdata->local_quizinteractions_log = [
            [
                'id' => 1,
                'attemptid' => 1,
                'userid' => 1,
                'quizid' => 1,
                'timecreated' => 1700000000,
                'interactions' => json_encode([
                    [
                        'slot' => 1,
                        'questionid' => 1,
                        'answerid' => 0,
                        'attemptid' => 1,
                        'position' => 0,
                        'action' => 'selected',
                        'timestamp' => 5000,
                        'datetime' => '2026-09-07T10:00:00Z',
                    ],
                    [
                        'slot' => 1,
                        'questionid' => 1,
                        'answerid' => 2,
                        'attemptid' => 1,
                        'position' => 1,
                        'action' => 'checked',
                        'timestamp' => 5001,
                        'datetime' => '2026-09-07T10:00:01Z',
                    ],
                ]),
            ],
        ];

        $config = $this->get_transformer_config();
        $config['repo'] = new \src\transformer\repos\TestRepository($testdata);

        $statements = \src\transformer\events\mod_quiz\attempt_submitted\handler($config, $event);

        $this->assertArrayHasKey('extensions', $statements[0]['result']);
        $this->assertArrayHasKey('https://example.com/xapi/extensions/quiz-interactions', $statements[0]['result']['extensions']);
        $interactions = $statements[0]['result']['extensions']['https://example.com/xapi/extensions/quiz-interactions'];
        $this->assertCount(2, $interactions);
        $this->assertFalse($interactions[0]['correct']);
        $this->assertTrue($interactions[1]['correct']);
        $this->assertSame(3, $interactions[0]['totalpossibleanswers']);
        $this->assertSame(1, $interactions[0]['attemptid']);
        $this->assertSame('2026-09-07T10:00:00Z', $interactions[0]['datetime']);
        $this->assertSame('test_question', $interactions[0]['questiontext']);
        $this->assertSame('answer 1', $interactions[0]['answertext']);
        $this->assertSame('answer 2', $interactions[1]['answertext']);
    }

    /**
     * Ensure the answered and reviewed payloads use the same shared enrichment.
     *
     * @return void
     */
    public function test_attempt_submitted_and_reviewed_filter_shared_interactions() {
        $event = $this->get_event();
        $testdata = $this->get_test_data();
        $testdata->question_attempts[0]->slot = 1;
        $testdata->local_quizinteractions_log = [[
            'id' => 1,
            'attemptid' => 1,
            'interactions' => json_encode([
                [
                    'slot' => 1,
                    'questionid' => 1,
                    'answerid' => 1,
                    'position' => 0,
                    'action' => 'selected',
                    'timestamp' => 5000,
                    'datetime' => '2026-09-07T10:00:00Z',
                ],
                [
                    'slot' => 2,
                    'questionid' => 1,
                    'answerid' => 2,
                    'position' => 1,
                    'action' => 'checked',
                    'timestamp' => 5001,
                    'datetime' => '2026-09-07T10:00:01Z',
                ],
            ]),
        ]];
        $config = $this->get_transformer_config();
        $config['repo'] = new \src\transformer\repos\TestRepository($testdata);

        $submitted = \src\transformer\events\mod_quiz\attempt_submitted\handler($config, $event);
        $answered = $submitted[1];
        $reviewevent = clone $event;
        $reviewevent->eventname = '\\mod_quiz\\event\\attempt_reviewed';
        $reviewed = \src\transformer\events\mod_quiz\attempt_reviewed($config, $reviewevent);

        $key = 'https://example.com/xapi/extensions/quiz-interactions';
        $this->assertCount(2, $submitted[0]['result']['extensions'][$key]);
        $this->assertCount(1, $answered['result']['extensions'][$key]);
        $this->assertSame(1, $answered['result']['extensions'][$key][0]['slot']);
        $this->assertEquals($submitted[0]['result']['extensions'][$key], $reviewed[0]['result']['extensions'][$key]);
    }

    /**
     * Ensure missing interaction rows are ignored without breaking the original statement.
     *
     * @return void
     */
    public function test_attempt_submitted_skips_missing_quiz_interactions() {
        $event = $this->get_event();
        $testdata = $this->get_test_data();
        $testdata->local_quizinteractions_log = [];

        $config = $this->get_transformer_config();
        $config['repo'] = new \src\transformer\repos\TestRepository($testdata);

        $statements = \src\transformer\events\mod_quiz\attempt_submitted\handler($config, $event);

        $this->assertArrayNotHasKey('extensions', $statements[0]['result']);
    }

    /**
     * Ensure malformed and unrelated interaction rows do not affect generation.
     *
     * @return void
     */
    public function test_attempt_submitted_ignores_invalid_json_and_other_attempts() {
        $event = $this->get_event();
        $testdata = $this->get_test_data();
        $testdata->local_quizinteractions_log = [
            ['attemptid' => 2, 'interactions' => '[{"questionid":1}]'],
            ['attemptid' => 1, 'interactions' => 'not-json'],
        ];
        $config = $this->get_transformer_config();
        $config['repo'] = new \src\transformer\repos\TestRepository($testdata);

        $statements = \src\transformer\events\mod_quiz\attempt_submitted\handler($config, $event);

        $this->assertArrayNotHasKey('extensions', $statements[0]['result']);
    }

    /**
     * Keep the existing answer enrichment output while passing new raw fields through.
     *
     * @return void
     */
    public function test_answer_interaction_output_regression() {
        $event = $this->get_event();
        $testdata = $this->get_test_data();
        $testdata->quiz_attempts[0]->uniqueid = 987654321;
        $testdata->question_attempts[0]->questionusageid = 987654321;
        $rawinteraction = [
            'attemptid' => 1,
            'slot' => 1,
            'questionid' => 1,
            'answerid' => 1,
            'position' => 0,
            'action' => 'selected',
            'timestamp' => 5000,
            'datetime' => '2026-10-08T10:00:00Z',
            'eventtype' => 'answer',
            'dropdownclicktimestamp' => 4990,
            'elementname' => 'q1:1_answer',
        ];
        $testdata->local_quizinteractions_log = [[
            'id' => 1,
            'attemptid' => 1,
            'interactions' => json_encode([$rawinteraction]),
        ]];
        $config = $this->get_transformer_config();
        $config['repo'] = new \src\transformer\repos\TestRepository($testdata);

        $statements = \src\transformer\events\mod_quiz\attempt_submitted\handler($config, $event);
        $interaction = $statements[0]['result']['extensions'][
            'https://example.com/xapi/extensions/quiz-interactions'
        ][0];

        $this->assertEquals([
            'attemptid' => 1,
            'slot' => 1,
            'questionid' => 1,
            'answerid' => 1,
            'position' => 0,
            'action' => 'selected',
            'timestamp' => 5000,
            'datetime' => '2026-10-08T10:00:00Z',
            'eventtype' => 'answer',
            'dropdownclicktimestamp' => 4990,
            'elementname' => 'q1:1_answer',
            'correct' => false,
            'totalpossibleanswers' => 3,
            'questiontext' => 'test_question',
            'answertext' => 'answer 1',
            'resolution' => 'fallback',
        ], $interaction);
    }

    /**
     * Keep all event types raw at attempt level and only question events on per-question statements.
     *
     * @return void
     */
    public function test_interaction_event_types_and_statement_filtering() {
        $event = $this->get_event();
        $testdata = $this->get_test_data();
        $testdata->question_attempts[0]->slot = 1;
        $interactions = [
            [
                'attemptid' => 1, 'slot' => 1, 'questionid' => 1, 'answerid' => 1,
                'position' => 0, 'action' => 'selected', 'timestamp' => 1000,
                'datetime' => '2026-10-08T10:00:01Z',
            ],
            ['attemptid' => 1, 'eventtype' => 'verify', 'slot' => 1, 'timestamp' => 2000,
                'datetime' => '2026-10-08T10:00:02Z'],
            ['attemptid' => 1, 'eventtype' => 'tryagain', 'slot' => 1, 'questionid' => 1,
                'timestamp' => 3000, 'datetime' => '2026-10-08T10:00:03Z'],
            ['attemptid' => 1, 'eventtype' => 'verify', 'slot' => 1, 'questionid' => 99,
                'timestamp' => 4000, 'datetime' => '2026-10-08T10:00:04Z'],
            ['attemptid' => 1, 'eventtype' => 'return', 'slot' => 1, 'totalquestions' => 2,
                'timestamp' => 5000, 'datetime' => '2026-10-08T10:00:05Z'],
            ['attemptid' => 1, 'eventtype' => 'previouspage', 'slot' => 1, 'totalquestions' => 2,
                'timestamp' => 6000, 'datetime' => '2026-10-08T10:00:06Z'],
            ['attemptid' => 1, 'eventtype' => 'nextpage', 'slot' => 1, 'totalquestions' => 2,
                'timestamp' => 7000, 'datetime' => '2026-10-08T10:00:07Z'],
            ['attemptid' => 1, 'eventtype' => 'navigate', 'slot' => 1, 'targetslot' => 2,
                'totalquestions' => 2, 'timestamp' => 8000, 'datetime' => '2026-10-08T10:00:08Z'],
            ['attemptid' => 1, 'eventtype' => 'finishtest', 'slot' => 1, 'totalquestions' => 2,
                'timestamp' => 9000, 'datetime' => '2026-10-08T10:00:09Z'],
            ['attemptid' => 1, 'eventtype' => 'future_event', 'slot' => 1, 'timestamp' => 10000,
                'datetime' => '2026-10-08T10:00:10Z'],
            ['attemptid' => 1, 'eventtype' => 'verify', 'slot' => 2, 'questionid' => 1,
                'timestamp' => 11000, 'datetime' => '2026-10-08T10:00:11Z'],
            ['attemptid' => 1, 'eventtype' => 'verify', 'slot' => 2, 'questionid' => null,
                'timestamp' => 12000, 'datetime' => '2026-10-08T10:00:12Z'],
        ];
        $testdata->local_quizinteractions_log = [[
            'id' => 1,
            'attemptid' => 1,
            'interactions' => json_encode($interactions),
        ]];
        $config = $this->get_transformer_config();
        $config['repo'] = new \src\transformer\repos\TestRepository($testdata);

        $submitted = \src\transformer\events\mod_quiz\attempt_submitted\handler($config, $event);
        $reviewevent = clone $event;
        $reviewevent->eventname = '\\mod_quiz\\event\\attempt_reviewed';
        $reviewed = \src\transformer\events\mod_quiz\attempt_reviewed($config, $reviewevent);
        $key = 'https://example.com/xapi/extensions/quiz-interactions';
        $completedinteractions = $submitted[0]['result']['extensions'][$key];
        $answeredinteractions = $submitted[1]['result']['extensions'][$key];
        $receivedinteractions = $reviewed[0]['result']['extensions'][$key];

        $this->assertCount(count($interactions), $completedinteractions);
        $this->assertEquals($completedinteractions, $receivedinteractions);
        $this->assertSame(array_column($interactions, 'timestamp'), array_column($completedinteractions, 'timestamp'));
        for ($index = 1; $index < count($interactions); $index++) {
            $this->assertEquals($interactions[$index], $completedinteractions[$index]);
        }
        $this->assertCount(3, $answeredinteractions);
        $this->assertSame(['answer', 'verify', 'tryagain'], array_map(
            static function ($interaction) {
                return $interaction['eventtype'] ?? 'answer';
            },
            $answeredinteractions
        ));
        $this->assertSame(1, $answeredinteractions[0]['slot']);
        $this->assertArrayNotHasKey('correct', $answeredinteractions[1]);
        $this->assertArrayNotHasKey('questiontext', $answeredinteractions[1]);
        $this->assertArrayNotHasKey('answertext', $answeredinteractions[1]);
        $this->assertEquals($interactions[1], $answeredinteractions[1]);
        $this->assertEquals($interactions[2], $answeredinteractions[2]);
        $this->assertSame('navigate', $completedinteractions[7]['eventtype']);
        $this->assertSame(2, $completedinteractions[7]['targetslot']);
        $this->assertSame('future_event', $completedinteractions[9]['eventtype']);
        $this->assertArrayNotHasKey('correct', $completedinteractions[9]);
    }
}
