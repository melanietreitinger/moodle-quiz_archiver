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
 * This file defines the update_job_status webservice function that is
 * part of the quiz_archiver plugin.
 *
 * @package   quiz_archiver
 * @copyright 2024 Niels Gandraß <niels@gandrass.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_archiver\external;

// TODO: Remove after deprecation of Moodle 4.1 (LTS) on 08-12-2025
require_once($CFG->dirroot.'/mod/quiz/report/archiver/patch_401_class_renames.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use quiz_archiver\ArchiveJob;

defined('MOODLE_INTERNAL') || die();

/**
 * API endpoint to update the status of a quiz archiver job
 */
class update_job_status extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_TEXT, 'UUID of the job this artifact is associated with', VALUE_REQUIRED),
            'status' => new external_value(PARAM_TEXT, 'New status to set for job with UUID of jobid', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of return parameters
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status of the executed wsfunction'),
        ]);
    }

    /**
     * Execute the webservice function
     *
     * @param string $jobid_raw
     * @param string $status_raw
     * @return array
     * @throws \invalid_parameter_exception
     */
    public static function execute(
        string $jobid_raw,
        string $status_raw
    ): array {
        // Validate request
        $params = self::validate_parameters(self::execute_parameters(), [
            'jobid' => $jobid_raw,
            'status' => $status_raw,
        ]);

        try {
            $job = ArchiveJob::get_by_jobid($params['jobid']);

            // Check capabilities
            $context = \context_module::instance($job->get_cm_id());
            require_capability('mod/quiz_archiver:use_webservice', $context);

            if ($job->is_complete()) {
                return [
                    'status' => 'E_JOB_ALREADY_COMPLETED',
                ];
            }

            if (!$job->has_write_access(optional_param('wstoken', null, PARAM_TEXT))) {
                return [
                    'status' => 'E_ACCESS_DENIED',
                ];
            }

            $job->set_status($params['status']);
        } catch (\dml_exception $e) {
            return [
                'status' => 'E_UPDATE_FAILED',
            ];
        }

        // Report success
        return [
            'status' => 'OK',
        ];
    }

}
