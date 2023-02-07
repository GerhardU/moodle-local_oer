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
 * Open Educational Resources Plugin
 *
 * @package    local_oer
 * @author     Christian Ortner <christian.ortner@tugraz.at>
 * @copyright  2022 Educational Technologies, Graz, University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oer;

use local_oer\helper\filestate;
use local_oer\metadata\courseinfo;
use local_oer\metadata\courseinfo_sync;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/helper/testcourse.php');

/**
 * Class filestate_test
 *
 * @coversDefaultClass \local_oer\helper\filestate
 */
class filestate_test extends \advanced_testcase {
    /**
     * A file can have different states inside the oer plugin. These states are defined as constants in the filestate class.
     * The calculate_file_state function returns the estimated state along with some additional informations about the course where
     * the file is used:
     * - state: int value 0-3 with current state of the file (constants)
     *   - 0 STATE_FILE_ERROR - something is wrong, most likely the file has been edited in more than one course
     *   - 1 STATE_FILE_NOT_EDITED - file is in course, but not edited in oer plugin yet (no metadata stored)
     *   - 2 STATE_FILE_EDITED - metadata has been stored
     *   - 3 STATE_FILE_RELEASED - a release of the file exist in the snapshot table
     * - editorid: the courseid of the course that can edit the given file
     * - courses: a list of the moodle courses where this file is used
     * - writable: bool value if this file can be edited
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \file_exception
     * @throws \moodle_exception
     * @throws \stored_file_creation_exception
     */
    public function test_calculate_file_state() {
        $this->resetAfterTest(true);

        $this->setAdminUser();
        global $DB;
        $testcourse = new testcourse();
        $course1    = $this->getDataGenerator()->create_course();
        $course2    = $this->getDataGenerator()->create_course();
        $filename   = 'samefile';
        $content    = 'some content that will result in the same contenthash';
        list($draftid, $file) = $testcourse->generate_file($filename, null, $content);
        $testcourse->generate_resource($course1, $this->getDataGenerator(), $filename, null, $content);
        $testcourse->generate_resource($course2, $this->getDataGenerator(), $filename, null, $content);

        // Tests for state 0.
        $testcourse->set_file_to_non_release($course1->id, $file);
        $testcourse->set_file_to_non_release($course2->id, $file);
        $this->assertTrue($DB->record_exists('local_oer_files', ['courseid' => $course1->id]));
        $this->assertTrue($DB->record_exists('local_oer_files', ['courseid' => $course2->id]));
        $this->assert_file_state($file->get_contenthash(), $course1->id,
                                 filestate::STATE_FILE_ERROR, 0,
                                 0, false);
        $log = $DB->get_record('local_oer_log', ['courseid' => $course1->id]);
        $this->assertEquals(logger::LOGERROR, $log->type);
        $msg = 'Ambiguous metadata for file ' . $file->get_contenthash() .
               ' found. File has been edited in ' . 2 . ' courses';
        $this->assertEquals($msg, $log->message);

        // Tests for state 1.
        $DB->delete_records('local_oer_files', ['courseid' => $course1->id]);
        $DB->delete_records('local_oer_files', ['courseid' => $course2->id]);
        $this->assertFalse($DB->record_exists('local_oer_files', ['courseid' => $course1->id]));
        $this->assertFalse($DB->record_exists('local_oer_files', ['courseid' => $course2->id]));
        $this->assert_file_state($file->get_contenthash(), $course1->id,
                                 filestate::STATE_FILE_NOT_EDITED, 0,
                                 2, true);
        $this->assert_file_state($file->get_contenthash(), $course2->id,
                                 filestate::STATE_FILE_NOT_EDITED, 0,
                                 2, true);

        // Tests for state 2.
        $testcourse->set_file_to_non_release($course1->id, $file);
        $this->assertTrue($DB->record_exists('local_oer_files', ['courseid' => $course1->id]));
        $this->assertFalse($DB->record_exists('local_oer_files', ['courseid' => $course2->id]));
        $this->assert_file_state($file->get_contenthash(), $course1->id,
                                 filestate::STATE_FILE_EDITED, $course1->id,
                                 2, true);
        $this->assert_file_state($file->get_contenthash(), $course2->id,
                                 filestate::STATE_FILE_EDITED, $course1->id,
                                 2, false);

        // Tests for state 3.
        $testcourse->set_file_to_release($course1->id, $file);
        $sync = new courseinfo_sync();
        $sync->sync_course($course1->id);
        $snapshot = new snapshot($course1->id);
        $snapshot->create_snapshot_of_course_files();
        $this->assertTrue($DB->record_exists('local_oer_files', ['courseid' => $course1->id]));
        $this->assertFalse($DB->record_exists('local_oer_files', ['courseid' => $course2->id]));
        $this->assertTrue($DB->record_exists('local_oer_snapshot', ['contenthash' => $file->get_contenthash()]));
        $this->assert_file_state($file->get_contenthash(), $course1->id,
                                 filestate::STATE_FILE_RELEASED, $course1->id,
                                 2, false);
        $this->assert_file_state($file->get_contenthash(), $course2->id,
                                 filestate::STATE_FILE_RELEASED, $course1->id,
                                 2, false);

        // Test inheritance.
        $this->assert_file_state($file->get_contenthash(), $course2->id,
                                 filestate::STATE_FILE_RELEASED, $course1->id,
                                 2, false);
        delete_course($course1->id, false);
        // After the deletion of course 1 the file metadata will be inherited to course 2.
        // So course 2 has to be the editorid from now on.
        $this->assert_file_state($file->get_contenthash(), $course2->id,
                                 filestate::STATE_FILE_RELEASED, $course2->id,
                                 1, false);
        $DB->delete_records('local_oer_snapshot', ['contenthash' => $file->get_contenthash()]);
        $this->assert_file_state($file->get_contenthash(), $course2->id,
                                 filestate::STATE_FILE_EDITED, $course2->id,
                                 1, true);
    }

    /**
     * Helper function to test the filestates.
     *
     * @param string $contenthash         Moodle file contenthash
     * @param int    $courseid            Moodle courseid
     * @param int    $expectedstate       filestate constant that is expected in this test
     * @param int    $expectededitor      Moodle courseid of editing course that is expected in this test
     * @param int    $expectedcoursecount Expected coursecount
     * @param bool   $expectedwritable
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private function assert_file_state($contenthash, $courseid, $expectedstate, $expectededitor, $expectedcoursecount,
                                       $expectedwritable) {
        list($state, $editorid, $courses, $writable) = filestate::calculate_file_state($contenthash, $courseid);
        $this->assertEquals($expectedstate, $state);
        $this->assertEquals($expectededitor, $editorid);
        $this->assertCount($expectedcoursecount, $courses);
        $this->assertEquals($expectedwritable, $writable);
    }

    /**
     * Returns a bool value if the current course can edit the current file.
     *
     * @return void
     */
    public function test_metadata_writable() {
        $this->resetAfterTest(true);

        // Something is wrong with the file, so it should not be editable until the problem has been resolved.
        // The editor flag does nothing here.
        $this->assertFalse(filestate::metadata_writable(filestate::STATE_FILE_ERROR, false));
        $this->assertFalse(filestate::metadata_writable(filestate::STATE_FILE_ERROR, true));

        // The file has already been released, so it is not editable anymore
        // The editor flag does nothing here.
        $this->assertFalse(filestate::metadata_writable(filestate::STATE_FILE_RELEASED, false));
        $this->assertFalse(filestate::metadata_writable(filestate::STATE_FILE_RELEASED, true));

        // The file has not been edited yet, so it can be edited by any course that contains the file.
        // The editor flag does nothing here.
        $this->assertTrue(filestate::metadata_writable(filestate::STATE_FILE_NOT_EDITED, false));
        $this->assertTrue(filestate::metadata_writable(filestate::STATE_FILE_NOT_EDITED, true));

        // This file has been edited already, so the editor flag is relevant for the return value.
        $this->assertFalse(filestate::metadata_writable(filestate::STATE_FILE_EDITED, false));
        $this->assertTrue(filestate::metadata_writable(filestate::STATE_FILE_EDITED, true));

        // The function has been called with a non-defined state - so it should return false.
        $this->assertFalse(filestate::metadata_writable(12345, false));
        $this->assertFalse(filestate::metadata_writable(54321, true));
    }
}