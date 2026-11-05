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
 * Get online users broken down by role, respecting configured priority.
 *
 * @param int $window Time window in seconds
 * @return array Associative array [roleshortname => count]
 * @throws dml_exception
 */
function local_prometheus_get_online_users_by_role(int $window): array {
    global $DB;

    $config = get_config('local_prometheus');
    $cachettl = isset($config->onlinecachettl) ? (int)$config->onlinecachettl : 30;

    // Build cache key from window and config.
    $rolescsv = isset($config->onlineroles) ? $config->onlineroles : 'editingteacher,teacher,student';
    $contextscsv = isset($config->onlinecontexts) ? implode(',', (array)$config->onlinecontexts) : CONTEXT_SYSTEM . ',' . CONTEXT_COURSE;
    $cachekey = md5($window . '|' . $rolescsv . '|' . $contextscsv);

    $cache = cache::make('local_prometheus', 'usersonlinebyrole');

    // Check cache if TTL > 0.
    if ($cachettl > 0) {
        $cached = $cache->get($cachekey);
        if ($cached !== false && isset($cached['time']) && (time() - $cached['time']) < $cachettl) {
            return $cached['data'];
        }
    }

    // Compute from DB.
    $roles = array_filter(array_map('trim', explode(',', $rolescsv)));
    $contexts = array_filter(array_map('intval', explode(',', $contextscsv)));

    if (empty($roles) || empty($contexts)) {
        return [];
    }

    // Get online user IDs.
    $onlineuserids = $DB->get_fieldset_select(
        'user',
        'id',
        'lastaccess > UNIX_TIMESTAMP(NOW() - INTERVAL ? SECOND)',
        [$window]
    );

    if (empty($onlineuserids)) {
        return [];
    }

    // Fetch role shortnames keyed by ID for quick lookup.
    list($inrolesql, $inroleparams) = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED);
    $rolesbyid = $DB->get_records_sql(
        "SELECT id, shortname FROM {role} WHERE shortname $inrolesql",
        $inroleparams
    );
    $roleshortnames = array_column($rolesbyid, 'shortname', 'id');

    // Build context filter.
    list($incontextsql, $incontextparams) = $DB->get_in_or_equal($contexts, SQL_PARAMS_NAMED);

    // Fetch role assignments for online users in selected contexts.
    list($inusersql, $inuserparams) = $DB->get_in_or_equal($onlineuserids, SQL_PARAMS_NAMED);
    $params = array_merge($inuserparams, $incontextparams);

    $sql = "SELECT ra.userid, ra.roleid, ctx.contextlevel
              FROM {role_assignments} ra
              JOIN {context} ctx ON ctx.id = ra.contextid
             WHERE ra.userid $inusersql
               AND ctx.contextlevel $incontextsql
          ORDER BY ra.userid";

    $assignments = $DB->get_records_sql($sql, $params);

    // Assign each user to the first matching role by priority.
    $userroles = [];
    foreach ($assignments as $assignment) {
        $userid = $assignment->userid;
        if (isset($userroles[$userid])) {
            continue; // Already assigned.
        }
        $roleid = $assignment->roleid;
        if (isset($roleshortnames[$roleid])) {
            $shortname = $roleshortnames[$roleid];
            // Check if this role is in the priority list.
            $priorityindex = array_search($shortname, $roles, true);
            if ($priorityindex !== false) {
                if (!isset($userroles[$userid])) {
                    $userroles[$userid] = ['shortname' => $shortname, 'priority' => $priorityindex];
                } else if ($priorityindex < $userroles[$userid]['priority']) {
                    $userroles[$userid] = ['shortname' => $shortname, 'priority' => $priorityindex];
                }
            }
        }
    }

    // Count by role.
    $counts = array_fill_keys($roles, 0);
    foreach ($userroles as $userid => $roledata) {
        $counts[$roledata['shortname']]++;
    }

    // Remove zero counts.
    $counts = array_filter($counts);

    // Store in cache.
    if ($cachettl > 0) {
        $cache->set($cachekey, ['time' => time(), 'data' => $counts]);
    }

    return $counts;
}

/**
 * Fetch user statistics metric
 *
 * @param int $window How far back to look for 'current' data
 * @return metric[]
 * @throws dml_exception
 */
function local_prometheus_get_userstatistics(int $window): array {
    global $DB;

    $brands = local_prometheus_parse_brandsites((string) get_config('local_prometheus', 'brandsites'));
    if ($brands) {
        return local_prometheus_get_brand_userstatistics($window, $brands);
    }

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
    
    // Add total count without labels (backward compatibility).
    $onlinemetric->add_value(
        new metric_value([], $currentlyonline)
    );

    // Add per-role breakdown.
    $rolecounts = local_prometheus_get_online_users_by_role($window);
    foreach ($rolecounts as $roleshortname => $count) {
        $onlinemetric->add_value(
            new metric_value(['role' => $roleshortname], $count)
        );
    }

    // Grab data about currently active users.
    $activedata = $DB->get_records_sql("
        SELECT
            MAX(id) AS max_id,
            auth,
            SUM(CASE WHEN deleted = 0 AND suspended = 0 THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN deleted = 1 AND suspended = 0 THEN 1 ELSE 0 END) AS deleted,
            SUM(CASE WHEN deleted = 0 AND suspended = 1 THEN 1 ELSE 0 END) AS suspended
        FROM {user}
        GROUP BY auth
    ");

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
        SELECT
            MAX(id) AS max_id,
            format,
            theme,
            SUM(CASE WHEN visible = 0 THEN 1 ELSE 0 END) AS hidden,
            SUM(CASE WHEN visible = 1 THEN 1 ELSE 0 END) AS visible
        FROM {course}
        GROUP BY format, theme
    ");

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
        SELECT
            e_stats.max_id,
            e_stats.enrol,
            e_stats.disabled,
            e_stats.enabled,
            COALESCE(ue_stats.active_enrolments, 0) AS active_enrolments,
            COALESCE(ue_stats.suspended_enrolments, 0) AS suspended_enrolments
        FROM (
            SELECT
                enrol,
                MAX(id) AS max_id,
                SUM(status = 1) AS disabled,
                SUM(status = 0) AS enabled
            FROM {enrol}
            GROUP BY enrol
        ) AS e_stats
        LEFT JOIN (
            SELECT
                e.enrol,
                SUM(ue.status = 0) AS active_enrolments,
                SUM(ue.status = 1) AS suspended_enrolments
            FROM {enrol} e
            JOIN {user_enrolments} ue ON ue.enrolid = e.id
            GROUP BY e.enrol
        ) AS ue_stats USING (enrol)
    ");

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
        SELECT
            m.id,
            m.name,
            SUM(CASE WHEN cm.deletioninprogress = 0 AND cm.visible = 1 THEN 1 ELSE 0 END) AS visible,
            SUM(CASE WHEN cm.deletioninprogress = 0 AND cm.visible = 0 THEN 1 ELSE 0 END) AS hidden
        FROM {modules} m
        LEFT JOIN {course_modules} cm ON cm.module = m.id
        GROUP BY m.id, m.name
    ");

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
        SELECT
            MAX(id) AS max_id,
            type,
            component,
            classname,
            hostname,
            COUNT(*) AS runs,
            SUM(result) AS failures
        FROM {task_log}
        WHERE timeend > ?
        GROUP BY component, classname, hostname, type
    ", [ $window ]);

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

/**
 * Parse the brandsites setting.
 *
 * @param string $raw One "CATEGORY_IDNUMBER = Site name" per line
 * @return array<string, string> category idnumber => site name
 */
function local_prometheus_parse_brandsites(string $raw): array {
    $brands = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        if (strpos($line, '=') === false) {
            continue;
        }
        [$idnumber, $site] = array_map('trim', explode('=', $line, 2));
        if ($idnumber !== '' && $site !== '') {
            $brands[$idnumber] = $site;
        }
    }
    return $brands;
}

/**
 * Course ids under each brand's top-level category.
 *
 * @param array<string, string> $brands category idnumber => site name
 * @return array<string, int[]> site name => course ids (brands without courses included, empty)
 */
function local_prometheus_get_brand_courses(array $brands): array {
    global $DB;

    $result = array_fill_keys(array_values($brands), []);

    [$insql, $params] = $DB->get_in_or_equal(array_keys($brands), SQL_PARAMS_NAMED);
    $roots = $DB->get_records_select_menu('course_categories', "depth = 1 AND idnumber $insql", $params, '', 'id, idnumber');
    if (!$roots) {
        return $result;
    }

    $courses = $DB->get_records_sql(
        'SELECT c.id, cc.path FROM {course} c JOIN {course_categories} cc ON cc.id = c.category'
    );
    foreach ($courses as $course) {
        $rootid = (int) explode('/', $course->path)[1];
        if (isset($roots[$rootid])) {
            $result[$brands[$roots[$rootid]]][] = (int) $course->id;
        }
    }
    return $result;
}

/**
 * User metrics split by brand, for a Moodle serving several brands.
 *
 * A user counts as online for a brand when they accessed one of its courses
 * within the window (user_lastaccess), and as active/deleted/suspended for every
 * brand they are enrolled in. Every value carries its own site label, which
 * overrides the shared one; no site-wide user series is emitted.
 *
 * @param int $window Seconds to consider a user online
 * @param array<string, string> $brands category idnumber => site name
 * @return metric[]
 */
function local_prometheus_get_brand_userstatistics(int $window, array $brands): array {
    global $DB;

    $config = get_config('local_prometheus');
    $roles = array_values(array_filter(array_map('trim',
        explode(',', $config->onlineroles ?? 'editingteacher,teacher,student'))));
    $cutoff = time() - $window;

    $onlinemetric = new metric('moodle_users_online', metric::TYPE_GAUGE,
        get_string('metric:onlineusers', 'local_prometheus'));
    $activemetric = new metric('moodle_users_active', metric::TYPE_GAUGE,
        get_string('metric:activeusers', 'local_prometheus'));
    $deletedmetric = new metric('moodle_users_deleted', metric::TYPE_GAUGE,
        get_string('metric:deletedusers', 'local_prometheus'));
    $suspendedmetric = new metric('moodle_users_suspended', metric::TYPE_GAUGE,
        get_string('metric:suspendedusers', 'local_prometheus'));

    foreach (local_prometheus_get_brand_courses($brands) as $site => $courseids) {
        $rolecounts = array_fill_keys($roles, 0);
        $online = [];
        $activedata = [];

        if ($courseids) {
            [$incourses, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

            $online = $DB->get_fieldset_sql(
                "SELECT DISTINCT ula.userid
                   FROM {user_lastaccess} ula
                   JOIN {user} u ON u.id = ula.userid AND u.deleted = 0
                  WHERE ula.courseid $incourses AND ula.timeaccess > :cutoff",
                $params + ['cutoff' => $cutoff]
            );

            if ($online && $roles) {
                [$inusers, $uparams] = $DB->get_in_or_equal($online, SQL_PARAMS_NAMED, 'u');
                [$inroles, $rparams] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'r');
                $assignments = $DB->get_recordset_sql(
                    "SELECT ra.id, ra.userid, r.shortname
                       FROM {role_assignments} ra
                       JOIN {role} r ON r.id = ra.roleid
                       JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :courselevel
                      WHERE ra.userid $inusers AND r.shortname $inroles AND ctx.instanceid $incourses",
                    $uparams + $rparams + $params + ['courselevel' => CONTEXT_COURSE]
                );
                // Each user counts once, under the first configured role they hold in the brand.
                $priority = array_flip($roles);
                $userrole = [];
                foreach ($assignments as $a) {
                    if (!isset($userrole[$a->userid]) || $priority[$a->shortname] < $priority[$userrole[$a->userid]]) {
                        $userrole[$a->userid] = $a->shortname;
                    }
                }
                $assignments->close();
                foreach ($userrole as $shortname) {
                    $rolecounts[$shortname]++;
                }
            }

            $activedata = $DB->get_records_sql(
                "SELECT u.auth,
                        SUM(CASE WHEN u.deleted = 0 AND u.suspended = 0 THEN 1 ELSE 0 END) AS active,
                        SUM(CASE WHEN u.deleted = 1 AND u.suspended = 0 THEN 1 ELSE 0 END) AS deleted,
                        SUM(CASE WHEN u.deleted = 0 AND u.suspended = 1 THEN 1 ELSE 0 END) AS suspended
                   FROM {user} u
                  WHERE u.id IN (
                        SELECT ue.userid
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE e.courseid $incourses)
               GROUP BY u.auth",
                $params
            );
        }

        $onlinemetric->add_value(new metric_value(['site' => $site], count($online)));
        foreach ($rolecounts as $shortname => $count) {
            $onlinemetric->add_value(new metric_value(['site' => $site, 'role' => $shortname], $count));
        }
        foreach ($activedata as $item) {
            $labels = ['site' => $site, 'auth' => $item->auth];
            $activemetric->add_value(new metric_value($labels, $item->active));
            $deletedmetric->add_value(new metric_value($labels, $item->deleted));
            $suspendedmetric->add_value(new metric_value($labels, $item->suspended));
        }
    }

    return [$onlinemetric, $activemetric, $deletedmetric, $suspendedmetric];
}

