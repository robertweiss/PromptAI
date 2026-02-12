<?php namespace ProcessWire;

class SSE {
    public static function header() {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Connection: keep-alive');

        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        ini_set('output_buffering', '0');
        ini_set('zlib.output_compression', '0');
        set_time_limit(0);
        ini_set('max_execution_time', 0);

        if (ob_get_level() == 0) {
            ob_start();
        }
    }

    public static function send(string $data, string $event = 'message', $addKeepAlive = false) {
        echo "event: {$event}\n";
        echo "data: ".json_encode($data)."\n\n";
        if ($addKeepAlive) {
            echo ": keep-alive\n\n";
        }
        echo str_pad('', 8196)."\n";
        ob_flush();
        flush();
    }

    public static function ping() {
        echo ": ping\n\n";
        ob_flush();
        flush();
    }

    public static function close() {
        echo "event: close\n";
        echo "data:\n\n";
        echo str_pad('', 8196)."\n";

        ob_flush();
        flush();
    }
}
