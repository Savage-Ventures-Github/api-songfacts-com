<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMID_Logger {

    private static function get_log_dir() {
        $dir = SMID_PLUGIN_DIR . 'logs/';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            // Prevent direct browser access
            file_put_contents( $dir . '.htaccess', 'deny from all' );
        }
        return $dir;
    }

    private static function get_log_file() {
        return self::get_log_dir() . 'smid-' . date( 'Y-m-d' ) . '.log';
    }

    public static function log( $level, $message, $context = array() ) {
        $log_file    = self::get_log_file();
        $timestamp   = date( 'Y-m-d H:i:s' );
        $context_str = ! empty( $context ) ? ' ' . json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
        $entry       = "[{$timestamp}] [{$level}] {$message}{$context_str}" . PHP_EOL;
        error_log( $entry, 3, $log_file );
    }

    public static function error( $message, $context = array() ) {
        self::log( 'ERROR', $message, $context );
    }

    public static function success( $message, $context = array() ) {
        self::log( 'SUCCESS', $message, $context );
    }

    public static function get_log_files() {
        $dir = self::get_log_dir();
        $files = glob( $dir . 'smid-*.log' );
        if ( ! $files ) {
            return array();
        }
        rsort( $files ); // newest first
        return $files;
    }

    public static function get_log_entries( $file, $limit = 200 ) {
        if ( ! $file || ! file_exists( $file ) ) {
            return array();
        }
        $lines   = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $lines   = array_reverse( $lines );        // newest first
        $entries = array();

        foreach ( array_slice( $lines, 0, $limit ) as $line ) {
            // Parse: [2026-06-16 12:00:00] [LEVEL] message {context}
            if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(ERROR|SUCCESS|INFO)\] (.+)$/', $line, $m ) ) {
                $entries[] = array(
                    'time'    => $m[1],
                    'level'   => $m[2],
                    'message' => $m[3],
                );
            }
        }
        return $entries;
    }

    public static function clear_log( $file ) {
        if ( $file && file_exists( $file ) && strpos( realpath( $file ), realpath( self::get_log_dir() ) ) === 0 ) {
            file_put_contents( $file, '' );
            return true;
        }
        return false;
    }
}
