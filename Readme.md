# A cron job handler for webhosts that do not allow you to set cron jobs and/or use `exec`.


## Features
- No cron tab needed
- No `exec` needed.

To work, it needs somebody to poll it, like e.g. cron-job.org.

It works without `exec` by calling itself over http to execute every job by `require`ing each job in a clean environment.

You supply it with a config file `.cron-job.json` (configurable with environment variable `WF_CRON_JOB_CONFIG_FILE`).

## Configuring jobs
The jobs need to be objects that have a `"file"` property which points to a script to run. The other properties are `"d"`, `"h"`, `"m"`, `"s"` which you use to set how long time between runs.

## Setting it up
You can generate passwords to use in the config file by running the file like this: `php cron-jobs.php passwords`. 

You then go to a cronjob service somewhere and ask them to poll your `<host>/cron-jobs.php?PASSWORD=<poll_password>`. You should of course first check yourself that it works.

Example `.cron-jobs.json`:
```json
{
    "errors_to_ignore" : [
        "set_time_limit(): Cannot set max execution time limit due to system policy"
    ],
    "debug": false,
    "force_webfork": false,
    "fork_password": "",
    "poll_password": "POLL",
    "request_scheme": "https",
    "server_name": "example.com",
    "log_dir": "/tmp/",
    "tz": "Europe/Copenhagen",
    "jobs": [
        {
            "file": "path/to/job.php",
            "m": 5,
            "s": 30
        },
        {
            "file": "other/path/to/job2.php",
            "h": 1,
            "m": 30
        }
    ]
}
```


Example log file output:

```
[2021-02-11 11:29:05] Checking for work... (on behalf of IP: 116.203.129.16, UA: `Mozilla/4.0 (compatible; cron-job.org; http://cron-job.org/abuse/)`)
[2021-02-11 11:29:05]   ↠ job #0 wp-content/plugins/modern-events-calendar/app/crons/g-export.php is scheduled in 127 s...
[2021-02-11 11:29:05]   ⑂ [webfork] Running freescout/artisan-schedule-run.php
[2021-02-11 11:29:09]   ✔ Ran job #1 freescout/artisan-schedule-run.php with webfork ⑂
[2021-02-11 11:29:09]     | Running freescout:fetch-monitor
[2021-02-11 11:29:09]     |   [2021-02-11 11:29:05] Fetching is working
[2021-02-11 11:29:09]     | Running freescout:check-conv-viewers
[2021-02-11 11:29:09]     | Running freescout:fetch-emails
[2021-02-11 11:29:09]     |   [2021-02-11 11:29:05] Fetching UNREAD emails for the last 3 days.
[2021-02-11 11:29:09]     |   [2021-02-11 11:29:05] Mailbox: The Board
[2021-02-11 11:29:09]     |   [2021-02-11 11:29:09] Folder: INBOX
[2021-02-11 11:29:09]     |   [2021-02-11 11:29:09] Fetched: 0
[2021-02-11 11:29:09]     |   [2021-02-11 11:29:09] Fetching finished
[2021-02-11 11:29:09]     | 
[2021-02-11 11:29:09] Ran 1 jobs, done
```

## Some notes

It keeps track of when to run jobs by modifying the last modification timestamp on the scripts when they are run so no database is needed. 

If you need to run an artisan script, you probably need to write a wrapper around your tasks, as artisan seems written to run from the command line.

I made this wrapper for Freescout that I use as one of my background jobs to poll emails.
I placed it in the Freescout root directory.
```php
<?php
define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

class HTMLOutput extends Symfony\Component\Console\Output\Output
{
    protected function doWrite($message, $newline)
    {
        echo "  $message";
        if ($newline) {
            echo PHP_EOL;
        }
    }
}

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
foreach ([
        'freescout:fetch-monitor',
        'freescout:check-conv-viewers',
        'freescout:fetch-emails'
    ] as $action) {
        echo "Running $action\n";
        $status = $kernel->handle(
            $input = new Symfony\Component\Console\Input\ArrayInput(['command' => $action]),
            new HTMLOutput
        );
    }

$kernel->terminate($input, $status);
```