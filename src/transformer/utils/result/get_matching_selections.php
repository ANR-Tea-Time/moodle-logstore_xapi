<?php

namespace src\transformer\utils\result;

defined('MOODLE_INTERNAL') || die();

/**
 * Parse Moodle's attempt-level matching response summary.
 *
 * @param string|null $responsesummary The stored response summary.
 * @return array<string, string>
 */
function get_matching_selections(?string $responsesummary): array {
    if ($responsesummary === null || $responsesummary === '') {
        return [];
    }

    $selections = [];
    foreach (explode('; ', $responsesummary) as $selection) {
        $split = explode("\n -> ", $selection, 2);
        if (count($split) === 2) {
            $selections[$split[0]] = $split[1];
        }
    }
    return $selections;
}
