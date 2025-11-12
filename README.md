# Moodle Prometheus reporting endpoint
A local plugin that presents an endpoint for Prometheus metric gathering.
Can be used either in Prometheus or as an InfluxDB v2 scraper

## Features
- **Online users by role**: The `moodle_users_online` metric now includes optional per-role breakdowns using a `role` label (e.g., `role="student"`, `role="teacher"`). The total count (without labels) is still exposed for backward compatibility.
- **Configurable**: Default online window, role priority list, context filtering (system/course/category), and cache TTL are all configurable via plugin settings.
- **Lightweight caching**: Role-based counts are cached using Moodle's cache API to minimize database load on high-traffic sites.

## Configuration
Navigate to **Site administration > Plugins > Local plugins > Prometheus reporting endpoint** to configure:
- **Default online window**: Time window (seconds) to consider a user online. Can be overridden by the `timeframe` URL parameter.
- **Roles to track**: Comma-separated list of role shortnames (e.g., `editingteacher,teacher,student`). Order defines priority if a user has multiple roles.
- **Contexts to check**: System, category, and/or course contexts to search for role assignments.
- **Cache TTL**: How long (seconds) to cache online-by-role counts. Set to 0 to disable caching.

## Developing
Plugins can add their own metrics to the output by adding their own `plugin_name_prometheus_get_metrics(int $window)` function
to lib.php. This function **must** return either one or more `\local_prometheus\metric` objects, or an empty array.
Plugins **should** give users the option to toggle metric gathering for that plugin on or off.

The `$window` parameter is a unix timestamp and is used for determining the cutoff for a 'current' value. For example to determine 
the number of currently online users we can only look at the last access timestamp. In this case, `$window` would mean "treat users 
active since this timestamp as currently online". 

Example implementation in lib.php:
```php

/**
 * Fetch metrics for the plugin
 *
 * @param int $window
 * @return metric[] 
 */
mod_example_prometheus_get_metrics(int $window): array {
    $metric = new metric(
        'moodle_mod_example_foo',
        metric::TYPE_GAUGE,
        'optional HELP text, can be omitted'
    );
    
    $metric->add_value(new metric_value(
        [ 'label' => 'foo' ],
        12
    ));
    
    return [ $metric ];
}
```

## Installation
The plugin requires Moodle 3.9 or later, and PHP 7.4 or later. You will also need some way of gathering, storing, and
using the metrics that the plugin generates.

There's no special installation steps or instructions, just install it as you would any other plugin

### Install from Moodle.org
- Download .zip file from https://moodle.org/plugins/local_prometheus
- Navigate to `/moodle/root/local`
- Extract the .zip to the current directory
- Go to your Moodle admin control panel, or `php /moodle/root/admin/cli/upgrade.php`

### Install from git
- Navigate to your Moodle root folder
- `git clone https://github.com/Vidalia/moodle-local_prometheus.git local/prometheus`
- Make sure that user:group ownership and permissions are correct
- Go to your Moodle admin control panel, or `php /moodle/root/admin/cli/upgrade.php`

### Install from .zip
- Download .zip file from GitHub
- Navigate to `/moodle/root/local/prometheus`
- Extract the .zip to the current directory
- Rename the `moodle-local_prometheus-master` directory to `prometheus`
- Go to your Moodle admin control panel, or `php /moodle/root/admin/cli/upgrade.php`
