<?php
define("CONFIG_FILE", $_SERVER["WF_CRON_JOB_CONFIG_FILE"] ?? ".cron-jobs.json");
define("CONFIG", json_decode(file_get_contents(CONFIG_FILE), true));
if ($argv[1] === "passwords") {
    die(bin2hex(random_bytes(32))."\n".bin2hex(random_bytes(32))."\n");
}
CONFIG or die("Could not parse " . CONFIG_FILE);

function color($str, $color) {
    if (get_config("disable_log_color", false)) {
        return $str;
    }
    $colors = ['dim'  => '2', 'green' => '0;32', 'red' => '0;31'];
    return "\033[".$colors[$color]."m$str\033[0m";
}

function get_config($key, $default = NULL) {
    return CONFIG[$key]
    ?? $default
    ?? die("Error: could not read required value \"$key\" from "
    . CONFIG_FILE
    . ", that value is required.\n");
}
date_default_timezone_set(get_config("tz", "UTC"));

define("POLL_PASSWORD", get_config("poll_password"));
define("FORK_PASSWORD", get_config("fork_password"));
define("THIS_FILE", $_SERVER["SCRIPT_NAME"]);
define("FILES_TO_RUN", get_config("jobs"));
define("CLI", ($argc > 0 && $argv[0] === THIS_FILE));
$password = $_REQUEST["PASSWORD"];

function each_job($func) {
    for ($i = 0; $i < count(FILES_TO_RUN); $i++) {
        $func(FILES_TO_RUN[$i], $i);
    }
}
each_job(function($job, $i) {
    if (!array_key_exists("file", $job)) {
        die("Error: all jobs in " . CONFIG_FILE . " must have a \"file\" value, job #$i did not\n");
    }
    if (!file_exists($job["file"])) {
        die("Error: file {$job["file"]} in job #$i does not exist\n");
    }
});
function four_oh_four() {
    http_response_code(404);
    echo "404 - file not found";
}
// Exit early if called without correct password
if ($password !== POLL_PASSWORD && $password !== FORK_PASSWORD && !CLI) {
    four_oh_four();
}

$stamp = date("Y-m-d");
$log_dir = get_config("log_dir", "/tmp");
define("LOG_FILE", "$log_dir/cron-job-$stamp.log");
define("LOG_LINK", "$log_dir/cron-job.log");
touch(LOG_FILE);
if (file_exists(LOG_LINK) && readlink(LOG_LINK) !== LOG_FILE) {
    unlink(LOG_LINK);
    symlink(LOG_FILE, LOG_LINK);
} else if (!file_exists(LOG_LINK)) {
    symlink(LOG_FILE, LOG_LINK);
}
function cron_debug($str) {
    if (get_config("debug", false)) {
        cron_log($str, "       \D\E\B\U\G       ");
    }
}
function cron_log($str, $stampFormat = "Y-m-d H:i:s") {
    $stamp = date($stampFormat);
    file_put_contents(LOG_FILE, "[$stamp] $str\n", FILE_APPEND);
    if (CLI) {
        echo "[$stamp] $str\n";
    }
}

function t($t) {
    return $t['d'] * 24 * 3600 + $t['h'] * 3600 + $t['m'] * 60 + $t['s'];
}

function should_run($job) {
    $path = $job["file"];

    $last_run = filemtime($path);
    $time_left = ($last_run + t($job)) - time();
    // 15 second margin, rather run early than late.
    // Free pollers run every minute
    return ($time_left < get_config("poll_margin", 15));
}

function run_job($i) {
    $job = FILES_TO_RUN[$i];

    $path = $job["file"];

    $last_run = filemtime($path);
    $time_left = ($last_run + t($job)) - time();
    // 15 second margin, rather run early than late.
    // Free pollers run every minute
    if (should_run($job)) {
        $result = null;
        $output = [];
        if (function_exists("exec") && !get_config("force_webfork", false)) {
            exec("php $path", $output, $result);
            $error = $result !== 0;
            $method = "exec";
        } else {
            // exec did not exist, so we do a webfork,
            // i.e call this script over http with parameters to have it run just one
            // job in a clean environment
            $server = get_config("server_name", $_SERVER["SERVER_NAME"]);
            $scheme = get_config("request_scheme", "https");
            $url = "$scheme://$server/".THIS_FILE."?PASSWORD=".FORK_PASSWORD."&i=$i";
            cron_debug("  ⑂ webfork: calling $url");
            $response = file_get_contents($url);
            cron_debug("  ⑂ webfork response:" . $response);
            $result = json_decode($response, true);
            if ($result === null) {
                $output = explode("\n", $response);
                $error = true;
            } else {
                $error = !$result["success"];
                $output = explode("\n", $result["result"]);
            }
            $method = "webfork ⑂";
        }
        $sigil =  $error ? color("x", "red") : color("✔", "green");
        cron_log("  $sigil Ran job #$i $path with $method");
        foreach ($output as $line) {
            cron_log("    | " . color(strip_tags($line), "dim"));
        }
        touch($path);
        return true;
    } else {
        cron_log("  ↠ job #$i $path is scheduled in $time_left s...");
    }
    return false;
}

function get_remote_info() {
    return "IP: {$_SERVER["REMOTE_ADDR"]}, UA: `{$_SERVER["HTTP_USER_AGENT"]}`";
}

if ($password === FORK_PASSWORD) {
    /**
     * Error-function to be called when an error occurs when calling the jobs.
     * Ignores certain errors that can be configured in the cron-jobs.json
     */
    $triggered_error = false;
    function error($severity, $message, $filename, $lineno) {
        global $triggered_error;

        $ignore_error = array_search($message, get_config("errors_to_ignore", [])) !== false;
        if (!$ignore_error) { $triggered_error = true; }

        $cwd  = getcwd();
        if (strpos($filename, $cwd) === 0) {
            // strlen + 1 to also remove the slash
            $filename = substr($filename, strlen($cwd) + 1);
        }
        $error_msg = "Error: '$message' in $filename:$lineno."
                    . ($ignore_error ? " (ignored)" : "") . "\n";
        cron_debug("    " . $error_msg);
        echo $error_msg;
    }

    $job = FILES_TO_RUN[$_GET["i"]];
    $file = $job["file"];
    if (should_run($job)) {
        cron_log("  ⑂ [webfork] Running $file");
        set_error_handler('error');
        ob_start(function($buffer){
            global $triggered_error;
            return json_encode([
                "success" => !$triggered_error,
                "result" => $buffer
            ]);
        });
        require($file);
        ob_end_flush();
    } else {
        cron_log("🔥 [webfork] Asked to run $file, but timestamp is too fresh.");
        cron_log("🔥 [webfork] From " . get_remote_info());
        four_oh_four();
    }
} else if ($password === POLL_PASSWORD || CLI) {
    $behalf_of = CLI ? "command line user '{$_ENV["USER"]}'"
        : get_remote_info();
    cron_log("Checking for work... (on behalf of $behalf_of)");
    $jobs_run = 0;
    each_job(function($job, $i) use (&$jobs_run) {
        if (run_job($i)) {
            $jobs_run++;
        }
    });
    cron_log("Ran $jobs_run jobs, done");
    echo "Ran $jobs_run jobs, thank you for polling\n";
}

?>