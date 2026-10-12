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

namespace logstore_xapi\mod_quiz\attempt_submitted\match;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/admin/tool/log/store/xapi/tests/xapi_test_case.php');

/**
 * Unit test for mod_quiz attempt submitted (match) event.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class match_test extends \logstore_xapi\xapi_test_case {

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
     * Ensure matching interactions use the question's real targets.
     *
     * @return void
     */
    public function test_attempt_submitted_enriches_matching_interactions() {
        $event = $this->get_event();
        $testdata = $this->get_test_data();
        $testdata->qtype_match_subquestions[] = (object) [
            'id' => 4,
            'questionid' => 1,
            'answertext' => 'Purple',
            'questiontext' => '<p>Yellow</p>',
        ];
        $testdata->local_quizinteractions_log = [[
            'id' => 1,
            'attemptid' => 1,
            'interactions' => json_encode([
                [
                    'slot' => 1,
                    'questionid' => 1,
                    'answerid' => 3,
                    'elementname' => 'sub3',
                    'position' => 2,
                    'action' => 'selected',
                    'timestamp' => 5000,
                    'datetime' => '2026-09-07T10:00:00Z',
                ],
                [
                    'slot' => 1,
                    'questionid' => 1,
                    'answerid' => 1,
                    'elementname' => 'sub1',
                    'position' => 0,
                    'action' => 'selected',
                    'timestamp' => 5001,
                    'datetime' => '2026-09-07T10:00:01Z',
                ],
                [
                    'slot' => 1,
                    'questionid' => 1,
                    'answerid' => 4,
                    'elementname' => 'sub4',
                    'position' => 3,
                    'action' => 'selected',
                    'timestamp' => 5002,
                    'datetime' => '2026-09-07T10:00:02Z',
                ],
                [
                    'slot' => 1,
                    'questionid' => 1,
                    'answerid' => 2,
                    'elementname' => 'sub2',
                    'position' => 1,
                    'action' => 'selected',
                    'timestamp' => 5003,
                    'datetime' => '2026-09-07T10:00:03Z',
                ],
            ]),
        ]];
        $config = $this->get_transformer_config();
        $config['repo'] = new \src\transformer\repos\TestRepository($testdata);

        $statements = \src\transformer\events\mod_quiz\attempt_submitted\handler($config, $event);
        $interaction = $statements[0]['result']['extensions']['https://example.com/xapi/extensions/quiz-interactions'][0];

        $interactions = $statements[0]['result']['extensions']['https://example.com/xapi/extensions/quiz-interactions'];
        $this->assertCount(4, $interactions);
        $this->assertSame([null, null, null, null], array_column($interactions, 'answertext'));
        $this->assertSame([null, null, null, null], array_column($interactions, 'correct'));
        $this->assertSame(['fallback', 'fallback', 'fallback', 'fallback'], array_column($interactions, 'resolution'));
        $this->assertSame([null, null, null, null], array_column($interactions, 'subquestiontext'));
        $this->assertSame('test_question', $interactions[0]['questiontext']);
    }
}
