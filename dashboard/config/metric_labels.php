<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Group labels
    |--------------------------------------------------------------------------
    |
    | The flat agent maps are grouped by the first key segment. Unknown
    | prefixes fall into "other".
    |
    */

    'groups' => [
        'cpanel' => 'cPanel',
        'security' => 'Security',
        'service' => 'Services',
        'mysql' => 'MySQL',
        'smart' => 'SMART',
        'diskquota' => 'Disk quota',
        'imunify' => 'Imunify',
        'other' => 'Other metrics',
    ],

    /*
    |--------------------------------------------------------------------------
    | Known key labels and types
    |--------------------------------------------------------------------------
    |
    | Types: text, count, percent, bytes, seconds, duration, bool.
    | Missing keys are humanised from their key path.
    |
    */

    'keys' => [
        'cpanel.version' => ['label' => 'cPanel version', 'type' => 'text'],
        'cpanel.accounts' => ['label' => 'Accounts', 'type' => 'count'],
        'cpanel.mailq' => ['label' => 'Mail queue', 'type' => 'count'],
        'cpanel.mysql_ping' => ['label' => 'MySQL ping', 'type' => 'text'],
        'cpanel.webserver' => ['label' => 'Web server', 'type' => 'text'],
        'cpanel.cagefs' => ['label' => 'CageFS', 'type' => 'text'],

        'security.csf_enabled' => ['label' => 'CSF enabled', 'type' => 'bool'],
        'security.csf_testing' => ['label' => 'CSF testing mode', 'type' => 'bool'],
        'security.modsec' => ['label' => 'ModSecurity', 'type' => 'text'],

        'mysql.available' => ['label' => 'Available', 'type' => 'bool'],
        'mysql.threads_running' => ['label' => 'Threads running', 'type' => 'count'],
        'mysql.long_queries' => ['label' => 'Long queries', 'type' => 'count'],
        'mysql.updated_at' => ['label' => 'Collected at', 'type' => 'text'],

        'smart.updated_at' => ['label' => 'Collected at', 'type' => 'text'],
        'smart.disks' => ['label' => 'Disks', 'type' => 'count'],
        'smart.bad_count' => ['label' => 'Disks with defects', 'type' => 'count'],
        'smart.status' => ['label' => 'Overall status', 'type' => 'text'],
        'smart.detail' => ['label' => 'Detail', 'type' => 'text'],
        'smart.cache_age_seconds' => ['label' => 'Report age', 'type' => 'seconds'],

        'diskquota.updated_at' => ['label' => 'Collected at', 'type' => 'text'],
        'diskquota.accounts' => ['label' => 'Accounts', 'type' => 'count'],
        'diskquota.over' => ['label' => 'Over quota', 'type' => 'count'],
        'diskquota.cache_age_seconds' => ['label' => 'Report age', 'type' => 'seconds'],

        'imunify.updated_at' => ['label' => 'Collected at', 'type' => 'text'],
        'imunify.scanning' => ['label' => 'Scanning', 'type' => 'bool'],
        'imunify.age_s' => ['label' => 'Scan age', 'type' => 'seconds'],
        'imunify.files' => ['label' => 'Files scanned', 'type' => 'count'],
        'imunify.target' => ['label' => 'Scan target', 'type' => 'text'],
        'imunify.cache_age_seconds' => ['label' => 'Report age', 'type' => 'seconds'],
    ],

    'fields' => [
        'health' => 'Health',
        'realloc' => 'Reallocated sectors',
        'pending' => 'Pending sectors',
        'power_on_hours' => 'Power-on hours',
        'written_gb' => 'Written GB',
        'used_gb' => 'Used GB',
        'installed' => 'Installed',
        'enabled' => 'Enabled',
        'running' => 'Running',
        'user' => 'User',
        'db' => 'Database',
        'state' => 'State',
        'info' => 'Query',
        'time' => 'Time',
    ],

];
