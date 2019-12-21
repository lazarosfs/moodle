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
 * This script allows to do backup.
 *
 * @package    core
 * @subpackage cli
 * @copyright  2013 Lancaster University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', 1);

require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
 require_once($CFG->dirroot . '/course/externallib.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array(
    'courseid' => false,
    'courseshortname' => '',
    'destination' => '',
    'categoryid' => '',
    'r' => false,
    'help' => false,
), array('h' => 'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help'] || (($options['courseid'] && $options['courseshortname']) &&
    !$options['categoryid']) || (($options['courseid'] || $options['courseshortname']) &&
    $options['categoryid']) || !$options['destination']) {
    $help = <<<EOL
Perform backup of the given course or categoryid.

Options:
--courseid=INTEGER          Course ID to backup.
--courseshortname=STRING    Course shortname for backup.
--categoryid=STRING         Category ID to backup.
--r                         Recursively backup all subcategories (with --categoryid)
--destination=STRING        Directory or filename to store backup(s). If not set the backup
                            will be stored within each course's backup file area.
-h, --help                  Print out this help.

Example:
\$sudo -u www-data /usr/bin/php admin/cli/backup.php --courseid=2 --destination=/moodle/backup/course_2.mbz\n
\$sudo -u www-data /usr/bin/php admin/cli/backup.php --categoryid=2 -r --destination=/moodle/backup/\n
EOL;

    echo $help;
    die;
}

$admin = get_admin();
if (!$admin) {
    mtrace("Error: No admin account was found");
    die;
}

// Do we need to store backup somewhere else?
$dest = rtrim($options['destination'], '/');
if (!empty($dest)) {
    if (is_dir($dest) && (!file_exists($dest) || !is_writable($dest))) {
        mtrace("Destination directory does not exists or not writable.");
        die();
    } else if (is_file($dest) && !is_writable($dest)) {
        mtrace("Destination file is not writable.");
        die();
    } else if (!is_dir($dest) && $options['categoryid']) {
        mtrace("You cannot backup entire Category to a file.");
        die();
    }
}
$coursestobk = array();

// Check that the course exists.
if ($options['courseid']) {
    $course = $DB->get_record('course', array('id' => $options['courseid']), '*', MUST_EXIST);
    array_push($coursestobk, $course);
} else if ($options['courseshortname']) {
    $course = $DB->get_record('course', array('shortname' => $options['courseshortname']), '*', MUST_EXIST);
    array_push($coursestobk, $course);
} else if ($options['categoryid']) {
    $category = $DB->get_record('course_categories', array('id' => $options['categoryid']), '*', MUST_EXIST);
    $coursestobk = $DB->get_records('course', array('category' => $options['categoryid']), '', 'id, fullname, shortname');
    if ($options['r']) {
        $categoriestobk = $DB->get_records_sql('SELECT id FROM {course_categories} WHERE ' .
            $DB->sql_like('path', ':path'), ['path' => '%/' . $category->id . '/%']);
        if ($categoriestobk) {
            foreach ($categoriestobk as $category) {
                $coursestobk = array_merge($coursestobk, $DB->get_records('course',
                    array('category' => $category->id), '', 'id, fullname, shortname'));
            }
        }
    }
}
foreach ($coursestobk as $course) {

    cli_heading('Performing backup of ' . $course->fullname . ' ('. $course->shortname .')...');
    $bc = new backup_controller(backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE,
        backup::INTERACTIVE_YES, backup::MODE_GENERAL, $admin->id);
    // Set the default filename.
    $format = $bc->get_format();
    $type = $bc->get_type();
    $id = $bc->get_id();
    $users = $bc->get_plan()->get_setting('users')->get_value();
    $anonymised = $bc->get_plan()->get_setting('anonymize')->get_value();
    $filename = backup_plan_dbops::get_default_backup_filename($format, $type, $id, $users, $anonymised);
    $bc->get_plan()->get_setting('filename')->set_value($filename);

    // Execution.
    $bc->finish_ui();
    $bc->execute_plan();
    $results = $bc->get_results();
    $file = $results['backup_destination']; // May be empty if file already moved to target location.

    // Do we need to store backup somewhere else?
    if (!empty($dest)) {
        if (is_dir($dest)) {
            $destfinal = $dest . '/' . $filename;
        } else {
            $destfinal = $dest;
        }
        if ($file) {
            mtrace("Writing " . $destfinal);
            if ($file->copy_content_to($destfinal)) {
                $file->delete();
                mtrace("Backup completed.");
            } else {
                mtrace("Destination directory does not exist or is not writable.
                    Leaving the backup in the course backup file area.");
            }
        }
    } else {
        mtrace("Backup completed, the new file is listed in the backup area of the given course");
    }
    $bc->destroy();
}
exit(0);
