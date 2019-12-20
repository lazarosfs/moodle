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
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/backup/cc/cc_lib/gral_lib/pathutils.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array(
    'courseid' => false,
    'courseshortname' => '',
    'categoryid' => '',
    'source' => '',
    'create' => false,
    'reset' => false,
    'mode' => 'general',
    'help' => false,
), array('h' => 'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}
// We either find an existing course by id or shortname or
// create a new in the category given with specific shortname if provided.

if ($options['help'] || ($options['courseid'] && $options['courseshortname']) ||
    ($options['courseid'] && $options['categoryid']) ||
    !(($options['courseid'] || $options['courseshortname']) ||
    $options['categoryid']) || !($options['source'])) {
    $help = <<<EOL
Perform course(s) restore from the given backup file or directory.

Options:
--courseid=INTEGER          Course ID to overwrite.
--categoryid=INTEGER        Category ID to restore/create course(s).
--courseshortname=STRING    Course shortname to overwrite (or to create
                            if categoryid exists and source is a file).
--source=STRING             Path to restore backup file or directory
                            (Courses will be created or overwriten).
--create                    If source is directory only create missing Course
--reset                     If source is directory only restore existing Courses
--mode=STRING               Could be any of general/hub/import/samesite (Optional)
-h, --help                  Print out this help.

Example:
\$sudo -u www-data /usr/bin/php admin/cli/restore.php --categoryid=1
                            --courseshortname=new_course --source=/saved_course.mbz\n
\$or\n
\$sudo -u www-data /usr/bin/php admin/cli/restore.php --categoryid=1
                            --source=/moodle/backup/mybackups\n
EOL;

    echo $help;
    die;
}

$admin = get_admin();
if (!$admin) {
    mtrace("Error: No admin account was found");
    die;
}
$source = rtrim($options['source'], '/');
if (!empty($source)) {
    if (!file_exists($source) || !(is_file($source) ||
        is_dir($source)) || !is_readable($source)) {
        mtrace("Error: Restore file or directory does not exist or is not readable");
        die;
    }
    if (is_dir($source) && !$options['categoryid']) {
        mtrace("Error: Source is a directory but no categoryid was given.");
        die();
    }
    if (is_dir($source) && $options['categoryid']) {
        if ($options['reset'] && $options['create']) {
            mtrace("Error: Only one of --reset or --create can be specified.");
            die();
        }
    }
    if (is_dir($source) && $options['categoryid'] && $options['courseshortname']) {
        mtrace("Warning: Source is a directory, courseshortname will be ignored.");
    }

    $modetypes = array('general' => backup::MODE_GENERAL, 'hub' => backup::MODE_HUB,
        'import' => backup::MODE_IMPORT, 'samesite' => backup::MODE_SAMESITE);

    $mode = backup::MODE_GENERAL;

    if ($options['mode']) {
        foreach ($modetypes as $modekey => $modevalue) {
            if ($options['mode'] == $modekey) {
                $mode = $modevalue;
            }
        }
    }

    $mbzfiles = array();
    if (is_file($source)) {
        array_push($mbzfiles, $source);
    }

    if (is_dir($source)) {
        $sourcefiles = new DirectoryIterator($source);
        foreach ($sourcefiles as $sourcefile) {
            if ($sourcefile->isDot()) {
                continue;
            }
            if ($sourcefile->getExtension() !== 'mbz') {
                continue;
            }
            array_push($mbzfiles, $source . '/' . $sourcefile);
        }
    }

    // Extract each file and restore each course.
    foreach ($mbzfiles as $mbzfile) {
        $transaction = $DB->start_delegated_transaction();
        $backupid = restore_controller::get_tempdir_name(SITEID, $admin->id);
        $path = $CFG->tempdir . '/backup/' . $backupid . '/';

        $packer = get_file_packer('application/vnd.moodle.backup');
        if (!$packer->extract_to_pathname($mbzfile, $path)) {
            mtrace('Error: Invalid backup file '.$mbzfile);
            continue;
        }
        $bcinfo = backup_general_helper::get_backup_information($backupid);
        $coursefullname = $bcinfo->original_course_fullname;
        $courseshortname = $bcinfo->original_course_shortname;
        $target = backup::TARGET_EXISTING_DELETING;

        if ($options['courseid']) {
            $course = $DB->get_record('course',
                array('id' => $options['courseid']), '*', MUST_EXIST);
            $categoryid = $course->category;
            $courseid = $course->id;
        } else if ($options['courseshortname'] && !$options['categoryid']) {
            $course = $DB->get_record('course',
                array('shortname' => $options['courseshortname']), '*', MUST_EXIST);
            $categoryid = $course->category;
            $courseid = $course->id;
        } else if ($options['categoryid']) {
            $count = $DB->count_records('course_categories',
                array('id' => $options['categoryid']));
            if ($count > 1) {
                mtrace("Error: More than one category with specified id found.");
                exit(0);
            } else {
                $category = $DB->get_record('course_categories',
                    array('id' => $options['categoryid']), '*', MUST_EXIST);
                $categoryid = $category->id;
            }
            if ($options['courseshortname']&&!(is_dir($source))) {
                $courseshortname = $options['courseshortname'];
            }
            $count = $DB->count_records('course',
                array('shortname' => $courseshortname, 'category' => $categoryid ));
            if ($count > 0) {
                if ($options['create'] && is_dir($source)) {
                    continue;
                }
                $course = $DB->get_record('course',
                    array('shortname' => $courseshortname,
                    'category' => $categoryid), '*');
                $courseid = $course->id;
            } else {
                if ($options['reset'] && is_dir($source)) {
                    continue;
                }
                $target = backup::TARGET_NEW_COURSE;
                $courseid = restore_dbops::create_new_course($coursefullname,
                    $courseshortname, $categoryid);
            }
        }
        if (!(empty($courseid)) &&!(empty($categoryid))) {
            cli_heading('Performing restore of "' . $coursefullname . '" (' .
                $bcinfo->original_course_shortname . ') to (' .
                $courseshortname . ' with id ' . $courseid . ' in category ' . $categoryid .
                ') from file ' . $mbzfile . ' (backupid is ' . $backupid . ' target is ' .
                ($target > 2 ? 'EXISTING_DELETING' : 'NEW_COURSE') . ' and mode is ' .
                $options['mode']);

            if ($target == backup::TARGET_EXISTING_DELETING) {
                echo "Removing Course content...";
                remove_course_contents($courseid, false);
            }

            $rc = new restore_controller($backupid, $courseid,
                backup::INTERACTIVE_NO, $mode, $admin->id, $target);
            $rc->execute_precheck();
            $results = $rc->get_precheck_results();
            foreach ($results as $type => $messages) {
                foreach ($messages as $index => $message) {
                    mtrace('precheck '.$type.'['.$index.'] = '.$message);
                }
            }
            $rc->execute_plan();

            $results = $rc->get_results();
            foreach ($results as $type => $messages) {
                foreach ($messages as $index => $message) {
                    mtrace('precheck '.$type.'['.$index.'] = '.$message);
                }
            }
            $rc->destroy();
        }
        $transaction->allow_commit();
        if (rtrim($path, '/') != $CFG->tempdir . '/backup') {
            rmdirr($path);
        }
        cli_heading('finished.');
    }
}
exit(0);
