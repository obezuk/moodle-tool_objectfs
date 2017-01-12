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
 *
 * @package   tool_sssfs
 * @author    Kenneth Hendricks <kennethhendricks@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_sssfs;

use core_files\filestorage\file_system;
use core_files\filestorage\file_storage;
use core_files\filestorage\stored_file;
use core_files\filestorage\file_exception;
use tool_sssfs\sss_client;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/sssfs/lib.php');

class sss_file_system extends file_system {

    private $sssclient;
    private $prefersss;

    /**
     * sss_file_system Constructor.
     *
     * Calls file_system contructor and sets S3 client.
     *
     * @param string $filedir The path to the local filedir.
     * @param string $trashdir The path to the trashdir.
     * @param int $dirpermissions The directory permissions when creating new directories
     * @param int $filepermissions The file permissions when creating new files
     * @param file_storage $fs The instance of file_storage to instantiate the class with.
     */
    public function __construct($filedir, $trashdir, $dirpermissions, $filepermissions, file_storage $fs = null) {
        parent::__construct($filedir, $trashdir, $dirpermissions, $filepermissions, $fs);
        $config = get_config('tool_sssfs');
        $sssclient = new sss_client($config);
        $this->set_sss_client($sssclient);
        $this->prefersss = $config->prefersss;
    }

    /**
     * Sets s3 client.
     *
     * We have this so we can inject a mocked one for unit testing.
     *
     * @param object $client s3 client
     */
    public function set_sss_client($client) {
        $this->sssclient = $client;
    }

    /**
     * Deletes local file based on it's content hash.
     *
     * @param  string $contenthash files contenthash
     *
     * @return bool success of operation
     */
    public function delete_local_file_from_contenthash($contenthash) {
        $this->ensure_readable_by_hash($contenthash);
        $filepath = $this->get_local_fullpath_from_hash($contenthash);
        return unlink($filepath);
    }

    /**
     * Copy file from s3 to local storage.
     *
     * @param  string $contenthash files contenthash
     *
     * @return bool success of operation
     */
    public function copy_sss_file_to_local($contenthash) {
        $localfilepath = $this->get_local_fullpath_from_hash($contenthash);
        $sssfilepath = $this->get_sss_fullpath_from_hash($contenthash);
        return copy($sssfilepath, $localfilepath);
    }

    /**
     * Copy file from local to s3 storage.
     *
     * @param  string $contenthash files contenthash
     *
     * @return bool success of operation
     */
    public function copy_local_file_to_sss($contenthash) {
        $this->ensure_readable_by_hash($contenthash);
        $localfilepath = $filepath = $this->get_local_fullpath_from_hash($contenthash);
        $sssfilepath = $this->get_sss_fullpath_from_hash($contenthash);
        return copy($localfilepath, $sssfilepath);
    }

    /**
     * Calculated md5 of file.
     *
     * @param  string $contenthash files contenthash
     *
     * @return string md5 hash of file
     */
    public function get_local_md5_from_contenthash($contenthash) {
        $localfilepath = $this->get_local_fullpath_from_hash($contenthash);
        $md5 = md5_file($localfilepath);
        return $md5;
    }

    /**
     * get location of contenthash file from the
     * tool_sssfs_filestate table. if content hash is not in the table,
     * we assume it is stored locally or is to be stored locally.
     *
     * @param  string $contenthash files contenthash
     *
     * @return int contenthash file location.
     */
    protected function get_hash_location($contenthash) {
        global $DB;
        $location = $DB->get_field('tool_sssfs_filestate', 'location', array('contenthash' => $contenthash));

        if ($location) {
            return $location;
        }

        return SSS_FILE_LOCATION_LOCAL;
    }

    /**
     * Returns path to the file if it was in s3.
     * Does not check if it actually is there.
     *
     * @param  stored_file $file stored file record
     *
     * @return string s3 file path
     */
    protected function get_sss_fullpath_from_file(stored_file $file) {
        return $this->get_sss_fullpath_from_hash($file->get_contenthash());
    }

    /**
     * Returns path to the file if it was in s3 based on conenthash.
     * Does not check if it actually is there.
     *
     * @param  string $contenthash files contenthash
     *
     * @return string s3 file path
     */
    protected function get_sss_fullpath_from_hash($contenthash) {
        $path = $this->sssclient->get_fullpath_from_hash($contenthash);
        return $path;
    }

    /**
     * Returns path to the file as if it was stored locally.
     * Does not check if it actually is there.
     *
     * Taken from get_fullpath_from_storedfile in parent class.
     *
     * @param  stored_file $file stored file record
     * @param  boolean     $sync sync external files.
     *
     * @return string local file path
     */
    protected function get_local_fullpath_from_file(stored_file $file, $sync = false) {
        if ($sync) {
            $file->sync_external_file();
        }
        return $this->get_local_fullpath_from_hash($file->get_contenthash());
    }

    /**
     * Returns path to the file as if it was stored locally from hash.
     * Does not check if it actually is there.
     *
     * Taken from get_fullpath_from_hash in parent class.
     *
     * @param  string $contenthash files contenthash
     *
     * @return string local file path
     */
    protected function get_local_fullpath_from_hash($contenthash) {
        return $this->filedir . DIRECTORY_SEPARATOR . $this->get_contentpath_from_hash($contenthash);
    }

    /**
     * Whether a file is readable locally. Will
     * try content recovery if not.
     *
     * Taken from is_readable in parent class.
     *
     * @param  stored_file $file stored file record
     *
     * @return boolean true if readable, false if not
     */
    protected function is_local_readable(stored_file $file) {
        $path = $this->get_local_fullpath_from_file($file, true);
        if (!is_readable($path)) {
            if (!$this->try_content_recovery($file) or !is_readable($path)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a file is readable in s3.
     *
     * @param  stored_file $file stored file record
     *
     * @return boolean true if readable, false if not
     */
    protected function is_sss_readable($file) {
        $path = $this->get_sss_fullpath_from_file($file);
        return is_readable($path);
    }

    /**
     * Whether a file is readable anywhere.
     * Will check if it can read local, and if it cant,
     * it will try to read from s3.
     *
     * We dont just call is_readable_by_hash because following
     * precedent set by parent, we try content recovery for local
     * files here.
     *
     * @param  stored_file $file stored file record
     *
     * @return boolean true if readable, false if not
     */
    public function is_readable(stored_file $file) {
        if ($this->is_local_readable($file) || $this->is_sss_readable($file)) {
            return true;
        }
        return false;
    }

    /**
     * Checks if a file is readable if it's path is local.
     *
     * @param  stored_file $file stored file record
     * @param  string $path file path
     *
     * @throws file_exception When the file could not be read locally.
     */
    protected function ensure_file_readable_if_local(stored_file $file, $path) {
        if ($this->sssclient->path_is_local($path) && !$this->is_local_readable($file)) {
            throw new file_exception('storedfilecannotread', '', $this->get_fullpath_from_storedfile($file));
        }
    }

    /**
     * Whether a file is readable anywhere by hash.
     * Will check if it can read local, and if it cant,
     * it will try to read from s3.
     *
     * Does not attempt content recovery if local.
     *
     * @param  string $contenthash files contenthash
     *
     * @return boolean true if readable, false if not
     */
    public function is_readable_by_hash($contenthash) {
        $isreadable = ($this->is_local_readable_by_hash($contenthash) || $this->is_sss_readable_by_hash($contenthash));
        return $isreadable;
    }

    /**
     * Checks if file is readable locally by hash.
     *
     * @param  string $contenthash files contenthash
     *
     * @return boolean true if readable, false if not
     */
    protected function is_local_readable_by_hash($contenthash) {
        $localpath  = $this->get_local_fullpath_from_hash($contenthash);
        return is_readable($localpath);
    }

    /**
     * Checks if file is readable in s3 by hash.
     *
     * @param  string $contenthash files contenthash
     *
     * @return boolean true if readable, false if not
     */
    protected function is_sss_readable_by_hash($contenthash) {
        $ssspath = $this->get_sss_fullpath_from_hash($contenthash);
        return is_readable($ssspath);
    }

    /**
     * Returns the fullpath for a given contenthash.
     * Queries the DB to determine file location and
     * then uses appropriate path function.
     *
     * @param  string $contenthash files contenthash
     *
     * @return string file path
     */
    protected function get_fullpath_from_hash($contenthash) {

        $filelocation  = $this->get_hash_location($contenthash);

        switch ($filelocation) {
            case SSS_FILE_LOCATION_LOCAL:
                return $this->get_local_fullpath_from_hash($contenthash);
            case SSS_FILE_LOCATION_DUPLICATED:
                if ($this->prefersss) {
                    return $this->get_sss_fullpath_from_hash($contenthash);
                } else {
                    return $this->get_local_fullpath_from_hash($contenthash);
                }
            case SSS_FILE_LOCATION_EXTERNAL:
                return $this->get_sss_fullpath_from_hash($contenthash);
            default:
                return $this->get_local_fullpath_from_hash($contenthash);
        }
    }

    public function readfile(stored_file $file) {
        $path = $this->get_fullpath_from_storedfile($file, true);
        $this->ensure_file_readable_if_local($file, $path);
        readfile_allow_large($path, $file->get_filesize());
    }


    public function get_content(stored_file $file) {
        $path = $this->get_fullpath_from_storedfile($file, true);
        $this->ensure_file_readable_if_local($file, $path);
        return file_get_contents($path);

    }

    public function get_content_file_handle($file, $type = stored_file::FILE_HANDLE_FOPEN) {
        $path = $this->get_fullpath_from_storedfile($file, true);
        $this->ensure_file_readable_if_local($file, $path);
        return self::get_file_handle_for_path($path, $type);
    }

}