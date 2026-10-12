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

/**
 * Generic quiz handler for the question answered event.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace src\transformer\events\mod_quiz\question_answered;

use src\transformer\utils as utils;
use src\transformer\events\mod_quiz\attempt_submitted as attempt_submitted;

/**
 * Generic handler for quiz question answered event.
 *
 * @param array $config The transformer config settings.
 * @param \stdClass $event The event to be transformed.
 * @param \stdClass $questionattempt The questionattempt object.
 * @return array
 */
function handler(array $config, \stdClass $event, \stdClass $questionattempt) {
    $repo = $config['repo'];
    $question = $repo->read_record_by_id('question', $questionattempt->questionid);

    switch ($question->qtype) {
        case 'essay':
            $statements = essay($config, $event, $questionattempt, $question);
            break;
        case 'gapselect':
            $statements = gapselect($config, $event, $questionattempt, $question);
            break;
        case 'truefalse':
            $statements = truefalse($config, $event, $questionattempt, $question);
            break;
        case 'randomsamatch':
            $statements = randomsamatch($config, $event, $questionattempt, $question);
            break;
        case 'shortanswer':
            $statements = shortanswer($config, $event, $questionattempt, $question);
            break;
        case 'match':
            $statements = matching($config, $event, $questionattempt, $question);
            break;
        case 'multichoice':
        case 'multichoiceset':
            $statements = multichoice($config, $event, $questionattempt, $question);
            break;
        case 'numerical':
            $statements = numerical($config, $event, $questionattempt, $question);
            break;
        default:
            return [];
    }

    $slot = isset($questionattempt->slot) ? (int) $questionattempt->slot : null;
    if ($slot === null) {
        return $statements;
    }
    $interactions = attempt_submitted\get_quiz_interactions_extension(
        $config,
        $event,
        (int) $questionattempt->questionid,
        $slot
    );
    if ($interactions !== null) {
        foreach ($statements as &$statement) {
            $statement['result']['extensions'] = array_merge(
                $statement['result']['extensions'] ?? [],
                [attempt_submitted\QUIZ_INTERACTIONS_EXTENSION => $interactions]
            );
        }
        unset($statement);
    }

    return $statements;
}
