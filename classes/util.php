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
 * General utilities class for format_tiles.
 * @package    format_tiles
 * @copyright  2023 David Watson {@link http://evolutioncode.uk}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_tiles;

/**
 * General utilities class for format_tiles.
 * @package    format_tiles
 * @copyright  2023 David Watson {@link http://evolutioncode.uk}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {
    /**
     * Which course modules is the site administrator allowing to be displayed in a modal?
     * @return array the permitted modules including resource types e.g. page, pdf, HTML
     * @throws \dml_exception
     */
    public static function allowed_modal_modules() {
        $devicetype = \core_useragent::get_device_type();
        if ($devicetype != \core_useragent::DEVICETYPE_TABLET && $devicetype != \core_useragent::DEVICETYPE_MOBILE
            && !(\core_useragent::is_ie())) {
            // JS navigation and modals in Internet Explorer are not supported by this plugin so we disable modals here.
            $resources = get_config('format_tiles', 'modalresources');
            $modules = get_config('format_tiles', 'modalmodules');
            return [
                'resources' => $resources ? explode(",", $resources) : [],
                'modules' => $modules ? explode(",", $modules) : [],
            ];
        } else {
            return ['resources' => [], 'modules' => []];
        }
    }

    /**
     * Get information about a particular course module including whether modal is allowed.
     * Called by web service when deciding how to handle an activity click.
     * @param int $courseid
     * @param int $cmid
     * @return object|null
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_course_mod_info(int $courseid, int $cmid): ?object {
        global $CFG, $DB;
        $coursecontext = \context_course::instance($courseid);
        $modinfo = get_fast_modinfo($courseid);
        $cm = $modinfo->get_cm($cmid);
        require_capability('mod/' . $cm->modname . ':view', $coursecontext);

        $isresource = $cm->modname == 'resource';

        if ($cm->uservisible) {
            $completioninfo = $cm->completion && !isguestuser()
                ? (new \completion_info(get_course($courseid))) : null;
            $completiondata = $completioninfo
            && $completioninfo->is_enabled($cm) != COMPLETION_TRACKING_NONE ? $completioninfo->get_data($cm) : null;

            $allowedmodmodals = self::allowed_modal_modules();
            $resourcetype = $isresource ? self::get_mod_resource_icon_name($cm->context->id) : '';

            $modalallowed = ($resourcetype && in_array($resourcetype, $allowedmodmodals['resources']))
                || in_array($cm->modname, $allowedmodmodals['resources']) || in_array($cm->modname, $allowedmodmodals['modules']);
            $pluginfileurl = $isresource ? \format_tiles\output\course_output::plugin_file_url($cm) : '';
            if ($modalallowed && $cm->modname === 'url') {
                // Extra check that is set to embed.
                $url = $DB->get_record('url', ['id' => $cm->instance], '*', MUST_EXIST);
                require_once("$CFG->dirroot/mod/url/locallib.php");
                $displaytype = \url_get_final_display_type($url);
                if ($displaytype != RESOURCELIB_DISPLAY_EMBED) {
                    $modalallowed = false;
                }
                $modifiedvideourl = \format_tiles\output\course_output::check_modify_embedded_url($url->externalurl);
                $pluginfileurl = $modifiedvideourl ?: $url->externalurl;
            }

            return (object)[
                'id' => $cm->id,
                'courseid' => $courseid,
                'modulecontextid' => $cm->context->id,
                'coursecontextid' => $coursecontext->id,
                'name' => $cm->name,
                'modname' => $cm->modname,
                'sectionnumber' => $cm->sectionnum,
                'sectionid' => $cm->section,
                'completionenabled' => (bool)$completiondata,
                'completionstate' => $completiondata ? $completiondata->completionstate : null,
                'iscomplete' => in_array($completiondata->completionstate ?? null, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS])
                    ? 1 : 0,
                'ismanualcompletion' => $cm->completion == COMPLETION_TRACKING_MANUAL,
                'resourcetype' => $resourcetype,
                'pluginfileurl' => $pluginfileurl,
                'modalallowed' => $modalallowed,
            ];
        }
        return null;
    }

    /**
     * If we are not on a mobile device we may want to ensure that tiles are nicely fitted depending on our screen width.
     * E.g. avoid a row with one tile, centre the tiles on screen.  JS will handle this post page load.
     * However we want to handle it pre-page load if we can to avoid tiles moving around once page is loaded.
     * So we have JS send the width via AJAX on first load, and we remember the value and apply it next time using inline CSS.
     * This function gets the data to enable us to add the inline CSS.
     * This will hide the main tiles window on page load and display a loading icon instead.
     * Then post page load, JS will get the screen width, re-arrange the tiles, then hide the loading icon and show the tiles.
     * If session width var has already been set (because JS already ran), we set that width initially.
     * Then we can load the page immediately at that width without hiding anything.
     * The skipcheck URL param is there in case anyone gets stuck at loading icon and clicks it - they escape it for session.
     * @param int $courseid the course ID we are in.
     * @see format_tiles_external::set_session_width() for where the session vars are set from JS.
     * @return string the styles to print.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_tilefitter_extra_css(int $courseid): string {
        global $SESSION;
        if (!self::using_js_nav()) {
            return '';
        }
        if (!get_config('format_tiles', 'fittilestowidth')) {
            return '';
        }
        if (\core_useragent::get_device_type() == \core_useragent::DEVICETYPE_MOBILE) {
            return '';
        }
        if (optional_param('skipcheck', 0, PARAM_INT) || isset($SESSION->format_tiles_skip_width_check)) {
            $SESSION->format_tiles_skip_width_check = 1;
            return '';
        }

        // If session screen width has been set, send it to template so we can include in inline CSS.
        $sessionvar = 'format_tiles_width_' . $courseid;
        $sessionvarvalue = $SESSION->$sessionvar ?? 0;

        if ($sessionvarvalue == 0) {
            // If no session screen width has yet been set, we hide the tiles initially, so we can calculate correct width in JS.
            // We will remove this opacity later in JS.
            return ".format-tiles.course-$courseid.jsenabled:not(.editing) ul.tiles {opacity: 0;}";
        } else {
            return ".format-tiles.course-$courseid.jsenabled ul.tiles {max-width: {$sessionvarvalue}px;}";
        }
    }

    /**
     * Get the current Moodle major release as a float e.g. 4.3
     * Sometimes we need it, to avoid maintaining multiple versions of this plugin.
     * @return float
     */
    public static function get_moodle_release(): float {
        global $CFG;
        $matches = [];
        preg_match('/^(\d+\.\d+).*$/', $CFG->release, $matches);
        return $matches[1] ?? 0.0;
    }

    /**
     * Get the release details of this version of Tiles.
     * @return string
     */
    public static function get_tiles_plugin_release(): string {
        global $CFG;
        $plugin = new \stdClass();
        $plugin->release = '';
        require("$CFG->dirroot/course/format/tiles/version.php");
        preg_match('/^(\d+\.\d+).*$/', $plugin->release, $matches);
        return $matches[1] ?? 0.0;
    }

    /**
     * Generate html for course module content
     * (i.e. for the time being, the content of a page).
     * Necessary to ensure that references to src="@@PLUGINFILE@@..." in $record->content
     * are re-written to the correct URL
     *
     * @param string $modname e.g. page
     * @param \stdClass $record the database record from the module table (e.g. the page table if it's a page)
     * @param \context $context the context of the course module.
     * @return string HTML to output.
     */
    public static function format_cm_content_text(string $modname, \stdClass $record, \context $context): string {
        $text = '';
        if (isset($record->intro)) {
            $text .= file_rewrite_pluginfile_urls(
                $record->intro,
                'pluginfile.php',
                $context->id,
                'mod_' . $modname,
                'intro',
                null
            );
        }
        if (isset($record->content)) {
            $text .= \html_writer::div(file_rewrite_pluginfile_urls(
                $record->content,
                'pluginfile.php',
                $context->id,
                'mod_' . $modname,
                'content',
                $record->revision
            ));
        }
        $formatoptions = new \stdClass();
        $formatoptions->noclean = true;
        $formatoptions->overflowdiv = true;
        $formatoptions->context = $context;
        return format_text($text, $record->contentformat, $formatoptions);
    }


    /**
     * Get resource file type e.g. 'doc' from the icon URL e.g. 'document-24.png'
     * So that we know which icon to display on sub-tiles.
     *
     * @param int $modcontextid the mod info object we are checking
     * @return null|string the type e.g. 'doc'
     */
    public static function get_mod_resource_icon_name(int $modcontextid): ?string {
        $file = self::get_mod_resource_file($modcontextid);
        if (!$file) {
            return null;
        }
        $extensions = [
            'powerpoint' => 'ppt',
            'document' => 'doc',
            'spreadsheet' => 'xls',
            'archive' => 'zip',
            'application/pdf' => 'pdf',
            'mp3' => 'mp3',
            'mpeg' => 'mp4',
            'image/jpeg' => 'image',
            'image/png' => 'image',
            'image/gif' => 'image',
            'image/svg+' => 'image',
            'text/plain' => 'txt',
            'text/html' => 'html',
        ];
        $extension = $extensions[$file->get_mimetype()] ?? pathinfo($file->get_filename(), PATHINFO_EXTENSION);
        $extension = in_array($extension, ['docx', 'odf']) ? 'doc' : $extension;
        $extension = in_array($extension, ['xlsx', 'ods']) ? 'xls' : $extension;
        $extension = in_array($extension, ['pptx', 'odp']) ? 'ppt' : $extension;
        return $extension;
    }

    /**
     * Get the file relating to a resource course module from context ID.
     * @param int $modcontextid
     * @return \stored_file|null
     * @throws \coding_exception
     */
    public static function get_mod_resource_file(int $modcontextid): ?\stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files($modcontextid, 'mod_resource', 'content');
        foreach ($files as $file) {
            if ($file->get_filesize() && $file->get_filename() != '.' && $file->get_mimetype()) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Is the user using JS navigation i.e. animated tiles?
     * @return bool
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function using_js_nav() {
        $userstopjsnav = get_user_preferences('format_tiles_stopjsnav', 0);

        // JS navigation and modals in Internet Explorer are not supported by this plugin so we disable JS nav here.
        return !$userstopjsnav && get_config('format_tiles', 'usejavascriptnav') && !\core_useragent::is_ie();
    }

    /**
     * Get the colour which should be used as the base course for this course
     * (Can depend on theme, plugin and/or course settings).
     * @param string $coursebasecolour the course base colour which we may use unless this overrides it.
     * @return string the hex colour
     * @throws \dml_exception
     */
    public static function get_tile_base_colour($coursebasecolour = ''): string {
        global $PAGE;
        $result = null;

        if (!(get_config('format_tiles', 'followthemecolour'))) {
            if (!$coursebasecolour) {
                // If no course tile colour is set, use plugin default colour.
                $result = get_config('format_tiles', 'tilecolour1');
            } else {
                $result = $coursebasecolour;
            }
        } else {
            // We are following theme's main colour so find out what it is.
            if (!$result || !preg_match('/^#[a-f0-9]{6}$/i', $result)) {
                // Many themes including boost theme and Moove use "brandcolor" so try to get that if current theme has it.
                $result = get_config('theme_' . $PAGE->theme->name, 'brandcolor');
                if (!$result) {
                    // If not got a colour yet, look where essential theme stores its brand color and try that.
                    $result = get_config('theme_' . $PAGE->theme->name, 'themecolor');
                }
            }
        }

        if (!$result || !preg_match('/^#[a-f0-9]{6}$/i', $result)) {
            // If still no colour set, use a default colour.
            $result = '#1670CC';
        }
        return $result;
    }
}
