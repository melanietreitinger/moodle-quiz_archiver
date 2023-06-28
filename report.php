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
 * This file defines the quiz archiver class.
 *
 * @package   quiz_archiver
 * @copyright 2023 Niels Gandraß <niels@gandrass.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\local\reports\report_base;
use quiz_archiver\ArchiveJob;
use quiz_archiver\FileManager;
use quiz_archiver\form\archive_quiz_form;
use quiz_archiver\output\job_overview_table;
use quiz_archiver\RemoteArchiveWorker;
use quiz_archiver\Report;

defined('MOODLE_INTERNAL') || die();

class quiz_archiver_report extends quiz_default_report {

    /** @var object course object. */
    protected $course;
    /** @var object course module object. */
    protected $cm;
    /** @var object the quiz settings object. */
    protected $quiz;
    /** @var context the quiz context. */
    protected $context;
    /** @var object Moodle admin settings object */
    protected $config;
    /** @var Report internal report instance */
    protected Report $report;

    public function __construct() {
        $this->config = get_config('quiz_archiver');
    }

    /**
     * Display the report.
     *
     * @param object $quiz this quiz.
     * @param object $cm the course-module for this quiz.
     * @param object $course the course we are in.
     * @return bool
     * @throws moodle_exception
     */
    public function display($quiz, $cm, $course): bool {
        global $CFG, $OUTPUT;

        $this->course = $course;
        $this->cm = $cm;
        $this->quiz = $quiz;
        $this->report = new Report($this->course, $this->cm, $this->quiz);

        // Check permissions.
        $this->context = context_module::instance($cm->id);
        require_capability('mod/quiz:grade', $this->context);
        require_capability('quiz/grading:viewstudentnames', $this->context);
        require_capability('quiz/grading:viewidnumber', $this->context);

        // Start output.
        $this->print_header_and_tabs($cm, $course, $quiz, 'archiver');

        // What sort of page to display?
        if (!quiz_has_questions($quiz->id)) {
            echo quiz_no_questions_message($quiz, $cm, $this->context);
        } else {
            echo "Course-ID: $course->id <br>";
            echo "CM-ID: $cm->id <br>";
            echo "Quiz-ID: $quiz->id <br>";

            echo $OUTPUT->render_from_template("quiz_archiver/overview", [
                "num_users_with_attempts" => sizeof($this->report->get_users_with_attempts()),
                "num_attempts" => sizeof($this->report->get_attempts())
            ]);

            $archive_quiz_form = new archive_quiz_form();
            if ($archive_quiz_form->is_submitted()) {
                $formdata = $archive_quiz_form->get_data();

                print_r($formdata);
                $this->initiate_archive_job($formdata->export_attempts, $formdata->export_course_backup);
            } else {
                $archive_quiz_form->display();
            }

            echo "CONFIG: "; print_r($this->config);

            echo "<br><br>Jobs:";
            $jobtbl = new job_overview_table('job_overview_table', $this->course->id, $this->cm->id, $this->quiz->id);
            $jobtbl->define_baseurl("$CFG->wwwroot/mod/quiz/report.php?mode=archiver&id=".optional_param('id', 0, PARAM_INT));
            $jobtbl->out(10, true);

            $fm = new FileManager($this->course->id, $this->cm->id, $this->quiz->id);
            foreach ($fm->get_stored_artifacts() as $file) {
                $url = moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename(),
                    true
                );
                echo '<br><a href="'.$url.'" target="_blank">'.$file->get_filename().'</a>';
            }
        }

        return true;
    }

    protected function initiate_archive_job(bool $export_attempts, bool $export_course_backup) {
        global $USER;

        // Create temporary webservice token
        $wstoken = external_generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $this->config->webservice_id,
            $this->config->webservice_userid,
            context_system::instance(),
            time() + ($this->config->job_timeout_min * 60),
            0
        );
        echo "<p>Created WsToken: $wstoken</p>";

        // Prepare task: Export quiz attempts
        $task_archive_quiz_attempts = null;
        if ($export_attempts) {
            $task_archive_quiz_attempts = [
                'attemptids' => [4] # FIXME TODO Remove DEBUG # array_values(array_map(fn($obj): int => $obj->attemptid, $this->report->get_attempts()))
            ];
        }

        // TODO: Prepare task: Export course backup
        $task_moodle_course_backup = null;
        if ($export_course_backup) {

        }

        // Request archive worker
        $worker = new RemoteArchiveWorker(rtrim($this->config->worker_url, '/').'/archive', 10, 20);
        $job = null;
        try {
            $job_metadata = $worker->enqueue_archive_job(
                $wstoken,
                $this->course->id,
                $this->cm->id,
                $this->quiz->id,
                $task_archive_quiz_attempts,
                $task_moodle_course_backup
            );

            // Persist job in database
            $job = ArchiveJob::create(
                $job_metadata->jobid,
                $this->course->id,
                $this->cm->id,
                $this->quiz->id,
                $USER->id,
                $wstoken,
                $job_metadata->status
            );

        } catch (UnexpectedValueException $e) {
            echo "Comminucation with archive worker failed: $e"; // TODO
        } catch (RuntimeException $e) {
            echo "Archive worker reportet an error: $e"; // TODO
        } catch (Exception $e) {
            echo "Unknown error occured while creating archive job: $e"; // TODO
        }

        echo "<br/><br/><p>Created job:</p><pre>"; print_r($job); echo "</pre></br>";

        //$this->delete_webservice_token($wstoken);
        //echo "<p>DELETED WsToken: $wstoken</p>";

        // ...

        // Test, move somewhere else!
    }

    /**
     * Removes / invalidates the given webservice token
     *
     * @param $wstoken Webservice token to remove
     * @return void
     * @throws dml_exception
     */
    protected function delete_webservice_token($wstoken) {
        global $DB;
        $DB->delete_records('external_tokens', array('token' => $wstoken, 'tokentype' => EXTERNAL_TOKEN_PERMANENT));
    }

    /**
     * Get the URL of the front page of the report that lists all the questions.
     * @return string the URL.
     */
    protected function base_url() {
        return new moodle_url('/mod/quiz/report.php',
            array('id' => $this->cm->id, 'mode' => 'archiver'));
    }

}
