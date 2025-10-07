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
 * Local library functions
 *
 * @package     local_prometheus
 * @copyright   2023 University of Essex
 * @author      John Maydew <jdmayd@essex.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_prometheus\metric;
use local_prometheus\metric_value;

/**
 * Fetch user statistics metric
 *
 * @param int $window How far back to look for 'current' data
 * @return metric[]
 * @throws dml_exception
 */
function local_prometheus_get_userstatistics(int $window): array {
    global $DB;

    // Grab data about currently online users (within the last window period).
    $onlinemetric = new metric(
        'moodle_users_online',
        metric::TYPE_GAUGE,
        get_string('metric:onlineusers', 'local_prometheus')
    );

    $currentlyonline = $DB->count_records_select(
        'user',
        'lastaccess > UNIX_TIMESTAMP(NOW() - INTERVAL ? SECOND)',
        [ $window ]
    );
    
    $onlinemetric->add_value(
        new metric_value([], $currentlyonline)
    );

    // Grab data about currently active users.
    $activedata = $DB->get_records_sql("
        SELECT	MAX(usr.id),
                auth,
                (
                    SELECT	COUNT('x')
                    FROM	{user}
                    WHERE	auth = usr.auth
                        AND	deleted = 0
                        AND	suspended = 0
                ) AS active,
                (
                    SELECT	COUNT('x')
                    FROM	{user}
                    WHERE	auth = usr.auth
                        AND	deleted = 1
                        AND	suspended = 0
                ) AS deleted,
                (
                    SELECT	COUNT('x')
                    FROM	{user}
                    WHERE	auth = usr.auth
                        AND	deleted = 0
                        AND	suspended = 1
                ) AS suspended
        FROM	{user} usr
        GROUP BY auth");

    $activemetric = new metric(
        'moodle_users_active',
        metric::TYPE_GAUGE,
        get_string('metric:activeusers', 'local_prometheus')
    );
    $deletedmetric = new metric(
        'moodle_users_deleted',
        metric::TYPE_GAUGE,
        get_string('metric:deletedusers', 'local_prometheus')
    );
    $suspendedmetric = new metric(
        'moodle_users_suspended',
        metric::TYPE_GAUGE,
        get_string('metric:suspendedusers', 'local_prometheus')
    );

    foreach ($activedata as $item) {
        $labels = [ 'auth' => $item->auth ];

        $activemetric->add_value(new metric_value($labels, $item->active));
        $deletedmetric->add_value(new metric_value($labels, $item->deleted));
        $suspendedmetric->add_value(new metric_value($labels, $item->suspended));
    }

    return [ $onlinemetric, $activemetric, $deletedmetric, $suspendedmetric ];
}

/**
 * Fetch course statistics metrics
 *
 * @param int $window How far back to look for 'current' data
 * @return metric[]
 * @throws dml_exception
 */
function local_prometheus_get_coursestatistics(int $window): array {
    global $DB;

    $coursedata = $DB->get_records_sql("
SELECT	MAX(course.id),
        format,
		theme,
		(
			SELECT	COUNT('x')
			FROM	{course}
			WHERE	format = course.format
				AND	theme = course.theme
				AND visible = 0
		) AS hidden,
		(
			SELECT	COUNT('x')
			FROM	{course}
			WHERE	format = course.format
                AND	theme = course.theme
                AND visible = 1
		) AS visible
FROM	{course} course
GROUP BY format, theme");

    $visiblemetric = new metric(
        'moodle_courses_visible',
        metric::TYPE_GAUGE,
        get_string('metric:coursesvisible', 'local_prometheus')
    );
    $hiddenmetric = new metric(
        'moodle_courses_hidden',
        metric::TYPE_GAUGE,
        get_string('metric:courseshidden', 'local_prometheus')
    );

    foreach ($coursedata as $item) {
        $labels = [
            'theme' => $item->theme,
            'format' => $item->format
        ];

        $visiblemetric->add_value(new metric_value($labels, $item->visible));
        $hiddenmetric->add_value(new metric_value($labels, $item->hidden));
    }

    return [ $visiblemetric, $hiddenmetric ];
}

/**
 * Get statistics about course enrolments
 * NB: Course IDs aren't included in labels as this would generally cause a very high
 * cardinality for any site with a large number of courses.
 *
 * @param int $window
 * @return metric[]
 * @throws dml_exception
 */
function local_prometheus_get_enrolstatistics(int $window): array {
    global $DB;

    $data = $DB->get_records_sql("
SELECT	max(id),
        enrol,
    (
        SELECT	COUNT('x')
        FROM	{enrol} enrol
        WHERE	enrol.enrol = outenrol.enrol
            AND	status = 1
    ) AS disabled,
    (
        SELECT	COUNT('x')
        FROM	{enrol} enrol
        WHERE	enrol.enrol = outenrol.enrol
            AND	status = 0
    ) AS enabled,
    (
        SELECT	COUNT('x')
        FROM	{user_enrolments} user_enrol
            INNER JOIN {enrol} enrol
                ON	enrol.id = user_enrol.enrolid
        WHERE	enrol.enrol = outenrol.enrol
            AND	user_enrol.status = 0
    ) AS active_enrolments,
    (
        SELECT	COUNT('x')
        FROM	{user_enrolments} user_enrol
            INNER JOIN {enrol} enrol
                ON	enrol.id = user_enrol.enrolid
        WHERE	enrol.enrol = outenrol.enrol
            AND	user_enrol.status = 1
    ) AS suspended_enrolments
FROM	{enrol} outenrol
GROUP BY enrol");

    $enabledmetric = new metric(
        'moodle_enrolments_enabled',
        metric::TYPE_GAUGE,
        get_string('metric:enrolsenabled', 'local_prometheus')
    );
    $disabledmetric = new metric(
        'moodle_enrolments_disabled',
        metric::TYPE_GAUGE,
        get_string('metric:enrolsdisabled', 'local_prometheus')
    );
    $activemetric = new metric(
        'moodle_enrolments_active',
        metric::TYPE_GAUGE,
        get_string('metric:enrolsactive', 'local_prometheus')
    );
    $suspendedmetric = new metric(
        'moodle_enrolments_suspended',
        metric::TYPE_GAUGE,
        get_string('metric:enrolssuspended', 'local_prometheus')
    );

    foreach ($data as $item) {
        $label = [ 'enrol' => $item->enrol ];

        $enabledmetric->add_value(new metric_value($label, $item->enabled));
        $disabledmetric->add_value(new metric_value($label, $item->disabled));
        $activemetric->add_value(new metric_value($label, $item->active_enrolments));
        $suspendedmetric->add_value(new metric_value($label, $item->suspended_enrolments));
    }

    return [ $enabledmetric, $disabledmetric, $activemetric, $suspendedmetric ];
}

/**
 * Get activity module usage statistics
 *
 * @param int $window
 * @return metric[]
 * @throws dml_exception
 */
function local_prometheus_get_modulestatistics(int $window): array {
    global $DB;

    $data = $DB->get_records_sql("
SELECT	id,
        name,
        (
            SELECT	COUNT('x')
            FROM	{course_modules} course_module
            WHERE	course_module.module = module.id
                AND	deletioninprogress = 0
                AND course_module.visible = 1
        ) AS visible,
        (
            SELECT	COUNT('x')
            FROM	{course_modules} course_module
            WHERE	course_module.module = module.id
                AND	deletioninprogress = 0
                AND course_module.visible = 0
        ) AS hidden
FROM	{modules} module
GROUP BY module.name, module.id");

    $visiblemetric = new metric(
        'moodle_modules_visible',
        metric::TYPE_GAUGE,
        get_string('metric:modulesvisible', 'local_prometheus')
    );
    $hiddenmetric = new metric(
        'moodle_modules_hidden',
        metric::TYPE_GAUGE,
        get_string('metric:moduleshidden', 'local_prometheus')
    );

    foreach ($data as $item) {
        $label = [ 'module' => $item->name ];

        $visiblemetric->add_value(new metric_value($label, $item->visible));
        $hiddenmetric->add_value(new metric_value($label, $item->hidden));
    }

    return [ $visiblemetric, $hiddenmetric ];
}

/**
 * Get task statistics
 *
 * @param int $window
 * @return metric[]
 * @throws dml_exception
 */
function local_prometheus_get_taskstatistics(int $window): array {
    global $DB;

    $tasks = $DB->get_records_sql("
SELECT	MAX(id),
        type,
        component,
        classname,
        hostname,
        COUNT('x') AS runs,
        SUM(result) AS failures
FROM	{task_log}
WHERE   timeend > ?
GROUP BY component, classname, hostname, type",
        [ $window ]);

    $runmetric = new metric(
        'moodle_task_runs',
        metric::TYPE_GAUGE,
        get_string('metric:taskruns', 'local_prometheus')
    );
    $failuremetric = new metric(
        'moodle_task_failures',
        metric::TYPE_GAUGE,
        get_string('metric:taskfailures', 'local_prometheus')
    );

    foreach ($tasks as $task) {
        $labels = [
            'type' => $task->type == 1 ? 'adhoc' : 'scheduled',
            'component' => $task->component,
            'classname' => $task->classname,
            'hostname' => $task->hostname
        ];

        $runmetric->add_value(new metric_value($labels, $task->runs));
        $failuremetric->add_value(new metric_value($labels, $task->failures));
    }

    return [ $runmetric, $failuremetric ];
}
