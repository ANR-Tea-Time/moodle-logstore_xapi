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
 * Transform for the quiz attempt submitted event.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace src\transformer\events\mod_quiz\attempt_submitted;

use src\transformer\utils as utils;

const QUIZ_INTERACTIONS_EXTENSION = 'https://example.com/xapi/extensions/quiz-interactions';

/**
 * Resolve a quiz attempt interaction log row into a set of enriched xAPI result extensions.
 *
 * The local_quizinteractions plugin stores raw interactions server-side and this plugin
 * should only use the question's own definition to determine correctness, never client-provided
 * data. We keep the enrichment optional so the original statement still builds when the plugin
 * is absent or no row exists.
 *
 * @param array $config The transformer config settings.
 * @param \stdClass $event The event to be transformed.
 * @param ?int $questionid Restrict the result to one question.
 * @param ?int $slot Restrict the result to one quiz slot.
 * @return ?array
 */
function get_quiz_interactions_extension(
    array $config,
    \stdClass $event,
    ?int $questionid = null,
    ?int $slot = null
): ?array {
    $repo = $config['repo'];
    try {
        $rows = $repo->read_records('local_quizinteractions_log', ['attemptid' => $event->objectid]);
    } catch (\Throwable $e) {
        return null;
    }

    if (empty($rows)) {
        return null;
    }

    $row = reset($rows);
    if (!isset($row->interactions) || $row->interactions === '') {
        return null;
    }

    $decoded = json_decode($row->interactions);
    if (is_object($decoded) && isset($decoded->interactions)) {
        $metadata = $decoded;
        $decoded = $decoded->interactions;
    } else {
        $metadata = (object) [];
    }
    if (!is_array($decoded)) {
        return null;
    }

    // Build live question resolution data once per slot. Interaction history can
    // contain several changes to the same row, so final response summaries must
    // never be used as the historical answer-text source.
    $livecontexts = [];
    foreach ($decoded as $interaction) {
        if (!is_object($interaction) || !isset($interaction->slot) ||
            (property_exists($interaction, 'eventtype') && $interaction->eventtype !== 'answer')) {
            continue;
        }
        $interactionslot = (int) $interaction->slot;
        if (!array_key_exists($interactionslot, $livecontexts)) {
            [$livequestion, $livequestionattempt] = get_live_quiz_question_context(
                $config,
                $event,
                $interactionslot
            );
            $livecontexts[$interactionslot] = build_live_resolution_context(
                $livequestion,
                $livequestionattempt
            );
        }
    }

    $enriched = [];
    foreach ($decoded as $interaction) {
        if (!is_object($interaction)) {
            continue;
        }

        $eventtype = property_exists($interaction, 'eventtype')
            ? $interaction->eventtype : 'answer';
        $interactionslot = isset($interaction->slot) ? (int) $interaction->slot : null;
        $hasquestionid = isset($interaction->questionid);
        $interactionquestionid = $hasquestionid ? (int) $interaction->questionid : null;

        if ($slot !== null && $interactionslot !== $slot) {
            continue;
        }
        if ($questionid !== null && $hasquestionid && $interactionquestionid !== $questionid) {
            continue;
        }

        if ($eventtype !== 'answer') {
            if ($slot !== null && !in_array($eventtype, ['verify', 'tryagain'], true)) {
                continue;
            }
            $enriched[] = (array) $interaction;
            continue;
        }

        // Reset derived values for every interaction so failed lookups cannot leak.
        $answertext = null;
        $correct = false;
        $totalpossibleanswers = 0;
        $answer = null;
        $subquestion = null;
        $resolution = 'fallback';

        $answerid = isset($interaction->answerid) ? (int) $interaction->answerid : null;

        if ($interactionquestionid === null || $answerid === null) {
            $enriched[] = (array) $interaction;
            continue;
        }

        try {
            $question = $repo->read_record('question', ['id' => $interactionquestionid]);
        } catch (\Throwable $e) {
            $enriched[] = (array) $interaction;
            continue;
        }

        $livecontext = $livecontexts[$interactionslot] ?? build_live_resolution_context(null, null);
        $livequestion = $livecontext['question'];
        $islivematching = ($question->qtype === 'match' || $question->qtype === 'randomsamatch') &&
            $livequestion !== null && !empty($livecontext['stemorder']) &&
            !empty($livecontext['choiceorder']) && !empty($livecontext['choices']) &&
            !empty($livecontext['right']);
        $islivemultichoice = ($question->qtype === 'multichoice' || $question->qtype === 'multichoiceset') &&
            $livequestion !== null && !empty($livecontext['answers']) && !empty($livecontext['answerorder']);
        if ($islivemultichoice || $islivematching) {
            $resolution = 'live';
        }

        $answers = [];
        if ($question->qtype !== 'match' && $question->qtype !== 'randomsamatch') {
            $answers = $repo->read_records('question_answers', ['question' => $interactionquestionid]);
        }
        foreach ($answers as $candidate) {
            if ((int) $candidate->id === $answerid) {
                $answer = $candidate;
                break;
            }
        }

        // Some capture implementations use a zero-based option index instead of
        // question_answers.id. Zero is valid and must be resolved explicitly.
        $answervalues = array_values($answers);
        if (
            $answer === null && is_numeric($answerid) && $answerid >= 0 &&
            array_key_exists($answerid, $answervalues)
        ) {
            $answer = $answervalues[$answerid];
        }

        $answertext = $answer && isset($answer->answer) ? utils\get_string_html_removed($answer->answer) : null;
        $correct = $answer !== null && isset($answer->fraction) && (float) $answer->fraction > 0;
        $totalpossibleanswers = count($answers);
        $subquestiontext = null;

        if (($question->qtype === 'multichoice' || $question->qtype === 'multichoiceset') &&
            $livequestion !== null && isset($livequestion->answers)
        ) {
            $liveanswers = $livecontext['answers'];
            $answerorder = $livecontext['answerorder'];

            $displayposition = isset($interaction->position) ? (int) $interaction->position : null;
            if ($displayposition !== null && array_key_exists($displayposition, $answerorder)) {
                $liveanswerid = $answerorder[$displayposition];
                $liveanswer = $liveanswers[$liveanswerid] ?? null;
                if ($liveanswer !== null) {
                    $answertext = utils\get_string_html_removed((string) $liveanswer->answer);
                    $correct = isset($liveanswer->fraction) && (float) $liveanswer->fraction > 0;
                }
            }
            $totalpossibleanswers = count($answerorder);
        }

        if ($question->qtype === 'match' || $question->qtype === 'randomsamatch') {
            if (!$islivematching) {
                $answertext = null;
                $subquestiontext = null;
                $correct = null;
            } else {
                $subquestions = $repo->read_records('qtype_match_subquestions', ['questionid' => $interactionquestionid]);
                $subquestionvalues = array_values($subquestions);
                $elementname = isset($interaction->elementname) ? (string) $interaction->elementname : null;
                $subquestionindex = matching_subquestion_index($elementname, $interaction->position ?? null);
                $subquestiontext = $livecontext['stems'][$subquestionindex] ?? null;
                $choices = $livecontext['choices'];
                if (empty($choices)) {
                    foreach ($subquestionvalues as $fallbacksubquestion) {
                        if (isset($fallbacksubquestion->id, $fallbacksubquestion->answertext)) {
                            $choices[(string) $fallbacksubquestion->id] =
                                utils\get_string_html_removed((string) $fallbacksubquestion->answertext);
                        }
                    }
                }
                $choice = null;
                if ($answerid > 0 && array_key_exists($answerid, $livecontext['choiceorder'])) {
                    $choicekey = $livecontext['choiceorder'][$answerid];
                    if (array_key_exists((string) $choicekey, $choices)) {
                        $choice = $choices[(string) $choicekey];
                    }
                }
                if ($choice === null && empty($livecontext['choiceorder']) && $answerid > 0) {
                    $choice = resolve_matching_choice($choices, $answerid);
                }
                if ($choice !== null) {
                    $answertext = $choice;
                }
                $stemkey = $livecontext['stemorder'][$subquestionindex] ?? null;
                $choicekey = $livecontext['choiceorder'][$answerid] ?? null;
                if (
                    $stemkey !== null && $choicekey !== null &&
                    isset($livecontext['right'][$stemkey])
                ) {
                    $correct = (string) $livecontext['right'][$stemkey] === (string) $choicekey;
                } else if ($subquestiontext !== null && $answertext !== null) {
                    $correct = false;
                }
                $totalpossibleanswers = count($choices) ?: count(array_values($subquestions));
            }
        }

        $enrichedinteraction = (array) $interaction;
        $enrichedinteraction['attemptid'] = isset($interaction->attemptid)
            ? (int) $interaction->attemptid : (int) $event->objectid;
        $enrichedinteraction['datetime'] = isset($interaction->datetime)
            ? $interaction->datetime : ($metadata->datetime ?? ($row->datetime ?? null));
        $enrichedinteraction['questionid'] = $interactionquestionid;
        $enrichedinteraction['answerid'] = $answerid;
        $enrichedinteraction['correct'] = $correct;
        $enrichedinteraction['totalpossibleanswers'] = $totalpossibleanswers;
        $enrichedinteraction['questiontext'] = utils\get_string_html_removed($question->questiontext ?? $question->name ?? '');
        $enrichedinteraction['answertext'] = $answertext;
        $enrichedinteraction['resolution'] = $resolution;
        if ($question->qtype === 'match' || $question->qtype === 'randomsamatch') {
            $enrichedinteraction['subquestiontext'] = $subquestiontext;
        }
        $enriched[] = $enrichedinteraction;
    }

    return empty($enriched) ? null : $enriched;
}

/**
 * Load the question definition belonging to this attempt and slot.
 *
 * Matching questions shuffle their choices per attempt, so their live question
 * object is authoritative. The repository fallback keeps unit tests independent
 * of Moodle's question engine.
 *
 * @param array $config The transformer configuration.
 * @param \stdClass $event The event being transformed.
 * @param ?int $slot The quiz slot.
 * @return ?object
 */
function get_live_quiz_question_context(array $config, \stdClass $event, ?int $slot): array {
    global $CFG;
    if (isset($CFG->dirroot) && file_exists($CFG->dirroot . '/question/engine/lib.php')) {
        require_once($CFG->dirroot . '/question/engine/lib.php');
    }
    if ($slot === null || !class_exists('\\question_engine')) {
        return [null, null];
    }

    try {
        $attempt = $config['repo']->read_record_by_id('quiz_attempts', $event->objectid);
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $questionattempt = $quba->get_question_attempt($slot);
        return [$questionattempt->get_question(), $questionattempt];
    } catch (\Throwable $e) {
        return [null, null];
    }
}

/**
 * Build stable per-attempt resolution data from one live question object.
 *
 * For qtype_match, stemorder and choiceorder are the attempt-specific shuffle
 * state. If the loaded question does not expose them, they are read from the
 * first attempt step's _stemorder and _choiceorder variables.
 *
 * @param ?object $livequestion The live Moodle question.
 * @param ?object $livequestionattempt The live Moodle question attempt.
 * @return array
 */
function build_live_resolution_context(?object $livequestion, ?object $livequestionattempt): array {
    $context = [
        'question' => $livequestion,
        'answers' => [],
        'answerorder' => [],
        'choices' => [],
        'choiceorder' => [],
        'stemorder' => [],
        'stems' => [],
        'right' => [],
        'correctresponse' => [],
        'subquestions' => [],
    ];
    if ($livequestion === null) {
        return $context;
    }

    $context['answers'] = isset($livequestion->answers) ? (array) $livequestion->answers : [];
    $context['answerorder'] = array_keys($context['answers']);
    if ($livequestionattempt !== null && is_callable([$livequestion, 'get_order'])) {
        $context['answerorder'] = array_values($livequestion->get_order($livequestionattempt));
    }

    if (isset($livequestion->choices)) {
        foreach ((array) $livequestion->choices as $key => $choice) {
            $context['choices'][(string) $key] = utils\get_string_html_removed((string) $choice);
        }
    }
    if (isset($livequestion->stems)) {
        foreach ((array) $livequestion->stems as $key => $stem) {
            $context['stems'][(string) $key] = utils\get_string_html_removed((string) $stem);
        }
    }
    if (isset($livequestion->right)) {
        $context['right'] = (array) $livequestion->right;
    }
    $livetype = method_exists($livequestion, 'get_type_name')
        ? $livequestion->get_type_name() : '';
    if ($livetype === 'match' || $livetype === 'randomsamatch') {
        $step = null;
        if ($livequestionattempt !== null && is_callable([$livequestionattempt, 'get_step'])) {
            $step = $livequestionattempt->get_step(0);
        }
        // stemorder and choiceorder are protected on qtype_match_question;
        // the first step is the reliable public source for this attempt's shuffle.
        $context['stemorder'] = $step !== null
            ? parse_match_order($step->get_qt_var('_stemorder')) : $context['stemorder'];
        $context['choiceorder'] = $step !== null
            ? parse_match_order($step->get_qt_var('_choiceorder'), true) : $context['choiceorder'];
        if (empty($context['right']) && isset($livequestion->right)) {
            $context['right'] = (array) $livequestion->right;
        }
        $canonicalstems = $context['stems'];
        $context['stems'] = [];
        foreach ($context['stemorder'] as $displayindex => $stemkey) {
            if (array_key_exists((string) $stemkey, $canonicalstems)) {
                $context['stems'][$displayindex] = $canonicalstems[(string) $stemkey];
            }
        }
    }
    if (method_exists($livequestion, 'get_correct_response')) {
        $context['correctresponse'] = (array) $livequestion->get_correct_response();
    }
    if (isset($livequestion->subquestions)) {
        $subquestions = (array) $livequestion->subquestions;
        $subquestionorder = $livequestionattempt !== null && is_callable([$livequestion, 'get_order'])
            ? array_values($livequestion->get_order($livequestionattempt)) : array_keys($subquestions);
        foreach ($subquestionorder as $subquestionid) {
            if (array_key_exists($subquestionid, $subquestions)) {
                $context['subquestions'][] = $subquestions[$subquestionid];
            }
        }
    }
    return $context;
}

/**
 * Parse a comma-separated matching order from a question attempt step.
 *
 * @param mixed $order The stored step variable.
 * @return array
 */
function parse_match_order($order, bool $onebased = false): array {
    if (is_array($order)) {
        return $order;
    }
    if (!is_string($order) || $order === '') {
        return [];
    }
    $values = array_values(array_filter(
        array_map('trim', explode(',', $order)),
        static function ($value) {
            return $value !== '';
        }
    ));
    if (empty($values)) {
        return [];
    }
    return $onebased ? array_combine(range(1, count($values)), $values) : $values;
}

/**
 * Convert elementname's subN identity to a zero-based subquestion index.
 *
 * @param ?string $elementname The captured element name.
 * @param mixed $position Fallback captured position.
 * @return int
 */
function matching_subquestion_index(?string $elementname, $position): int {
    if ($elementname !== null && preg_match('/sub(\d+)$/', $elementname, $matches)) {
        return (int) $matches[1];
    }
    return is_numeric($position) && (int) $position >= 0 ? (int) $position : 0;
}

/**
 * Resolve one matching choice from the stable live-attempt choice map.
 *
 * @param array $choices Choice values keyed by Moodle answer value.
 * @param int $answerid Captured answer value.
 * @return ?string
 */
function resolve_matching_choice(array $choices, int $answerid): ?string {
    if (array_key_exists((string) $answerid, $choices)) {
        return $choices[(string) $answerid];
    }

    // Repository fixtures may expose a zero-based list while captured Moodle
    // values are one-based. This is only a representation fallback; it is not
    // based on interaction order or the final response summary.
    $values = array_values($choices);
    $index = $answerid > 0 ? $answerid - 1 : $answerid;
    return array_key_exists($index, $values) ? $values[$index] : null;
}

/**
 * Transformer for quiz attempt submitted event.
 *
 * @param array $config The transformer config settings.
 * @param \stdClass $event The event to be transformed.
 * @return array
 */
function attempt_submitted(array $config, \stdClass $event) {
    $repo = $config['repo'];
    $user = $repo->read_record_by_id('user', $event->relateduserid);
    $course = $repo->read_record_by_id('course', $event->courseid);
    $attempt = $repo->read_record_by_id('quiz_attempts', $event->objectid);
    $coursemodule = $repo->read_record_by_id('course_modules', $event->contextinstanceid);
    $quiz = $repo->read_record_by_id('quiz', $attempt->quiz);
    $gradeitem = $repo->read_record('grade_items', [
        'itemmodule' => 'quiz',
        'iteminstance' => $quiz->id,
    ]);
    $attemptgrade = $repo->read_record('grade_grades', [
        'itemid' => $gradeitem->id,
        'userid' => $event->relateduserid
    ]);
    $lang = utils\get_course_lang($course);

    $statement = [[
        'actor' => utils\get_user($config, $user),
        'verb' => utils\get_verb('completed', $config, $lang),
        'object' => utils\get_activity\quiz_attempt(
            $config,
            $event->objectid,
            $event->contextinstanceid
        ),
        'result' => utils\get_attempt_result($config, $attempt, $gradeitem, $attemptgrade),
        'context' => [
            ...utils\get_context_base($config, $event, $lang, $course),
            'contextActivities' => [
                'parent' => utils\context_activities\get_parent(
                    $config,
                    $event->contextinstanceid,
                    true
                ),
                'category' => [
                    utils\get_activity\site($config),
                ],
            ],
        ]
    ]];

    $interactionextensions = get_quiz_interactions_extension($config, $event);
    if (!is_null($interactionextensions)) {
        $statement[0]['result']['extensions'] = array_merge(
            $statement[0]['result']['extensions'] ?? [],
            [
                QUIZ_INTERACTIONS_EXTENSION => $interactionextensions,
            ]
        );
    }

    // Do not delete local_quizinteractions_log rows here. Immediate removal risks losing the
    // only copy of the raw interaction data if the LRS send fails or the statement is retried.
    // A safer pattern is to purge rows older than a configurable retention window in the
    // local_quizinteractions plugin or a dedicated scheduled task in this plugin.
    return $statement;
}
