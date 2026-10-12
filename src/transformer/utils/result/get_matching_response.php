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
 * Transformer utility for retrieving CMI matching result response.
 *
 * @package   logstore_xapi
 * @copyright Milt Reder <milt@yetanalytics.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace src\transformer\utils\result;

use src\transformer\utils as utils;

/**
 * Transformer utility for retrieving CMI matching result response.
 *
 * @param array $config The transformer config settings.
 * @param \stdClass $questionattempt The question attempt object.
 * @param string $lang The language.
 */
function get_matching_response(
    array $config,
    \stdClass $questionattempt,
    string $lang
) {
    $selections = get_matching_selections($questionattempt->responsesummary);
    return implode(
        '[,]',
        array_map(
            function ($selectionkey, $selectionvalue) {
                return utils\slugify($selectionkey)
                    . '[.]'
                    . utils\slugify($selectionvalue);
            },
            array_keys($selections),
            array_values($selections)
        )
    );
}
