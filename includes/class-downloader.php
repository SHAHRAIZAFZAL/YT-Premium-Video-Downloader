<?php
/**
 * Downloader class for YT-DLP operations
 */

class YTDLP_Downloader {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_ytdlp_get_info', array($this, 'ajax_get_video_info'));
        add_action('wp_ajax_nopriv_ytdlp_get_info', array($this, 'ajax_get_video_info'));
        
        add_action('wp_ajax_ytdlp_download', array($this, 'ajax_download_video'));
        add_action('wp_ajax_nopriv_ytdlp_download', array($this, 'ajax_download_video'));
        
        add_action('wp_ajax_ytdlp_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_nopriv_ytdlp_progress', array($this, 'ajax_get_progress'));
        
        add_action('ytdlp_process_download', array($this, 'process_download_background'), 10, 4);
    }
    
    public function ajax_get_video_info() {
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        
        if (empty($url)) {
            wp_send_json_error(array('message' => 'Please enter a valid video URL.'));
            return;
        }
        
        $info = $this->get_video_info($url);
        
        if (is_wp_error($info)) {
            wp_send_json_error(array('message' => $info->get_error_message()));
            return;
        }
        
        wp_send_json_success($info);
    }
    
    public function ajax_download_video() {
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'mp4';
        $quality = isset($_POST['quality']) ? sanitize_text_field($_POST['quality']) : 'best';
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        
        if (empty($url)) {
            wp_send_json_error(array('message' => 'Please enter a valid video URL.'));
            return;
        }
        
        $settings = get_option('ytdlp_settings');
        
        if (empty($settings) || !isset($settings['allowed_formats'])) {
            wp_send_json_error(array('message' => 'Plugin settings not configured. Please check plugin settings.'));
            return;
        }
        
        // Validate format
        if (!in_array($format, $settings['allowed_formats'])) {
            wp_send_json_error(array('message' => 'Selected format is not allowed.'));
            return;
        }
        
        // Generate progress ID
        $progress_id = uniqid('dl_', true);
        
        // Set initial progress at 0% for smooth start
        set_transient('ytdlp_progress_' . $progress_id, array(
            'status' => 'starting',
            'progress' => 0,
            'percent' => 0,
            'message' => 'Preparing download...'
        ), 3600);
        
        // Start download in background
        wp_schedule_single_event(time(), 'ytdlp_process_download', array($url, $format, $quality, $progress_id, $title));
        
        // Return progress ID immediately
        wp_send_json_success(array('progress_id' => $progress_id));
    }
    
    public function ajax_get_progress() {
        // For progress polling, use a simpler check - just verify progress_id exists
        // This is called frequently and nonce might expire during long downloads
        $progress_id = isset($_POST['progress_id']) ? sanitize_text_field($_POST['progress_id']) : '';
        
        if (empty($progress_id)) {
            wp_send_json_error(array('message' => 'Invalid progress ID'));
            return;
        }
        
        // Verify progress_id format (security: must start with 'dl_')
        if (strpos($progress_id, 'dl_') !== 0) {
            wp_send_json_error(array('message' => 'Invalid progress ID format'));
            return;
        }
        
        $progress = get_transient('ytdlp_progress_' . $progress_id);
        
        if ($progress === false) {
            wp_send_json_error(array('message' => 'Progress not found'));
            return;
        }
        
        wp_send_json_success($progress);
    }
    
    private function execute_with_progress($command, $progress_id) {
        // Start with 0% for smoother progression
        set_transient('ytdlp_progress_' . $progress_id, array(
            'status' => 'starting',
            'progress' => 0,
            'percent' => 0,
            'message' => 'Initializing download...',
            'downloaded_bytes' => 0,
            'total_bytes' => 0,
            'speed' => 0
        ), 3600);
        
        // Use proc_open to capture both stdout and stderr (yt-dlp outputs progress to stderr)
        $descriptorspec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w")   // stderr
        );
        
        $process = proc_open($command, $descriptorspec, $pipes);
        $output = '';
        $last_progress = -1; // Start at -1 so any progress >= 0 will update
        $line_count = 0;
        $downloaded_bytes = 0;
        $total_bytes = 0;
        $speed = 0;
        $has_started = false;
        $reached_100_percent = false; // Track if we've reached 100% to prevent going backwards
        
        if (is_resource($process)) {
            // Close stdin as we don't need it
            fclose($pipes[0]);
            
            // Set streams to non-blocking
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            
            $stdout = $pipes[1];
            $stderr = $pipes[2];
            
            while (true) {
                $read = array($stdout, $stderr);
                $write = null;
                $except = null;
                
                // Check if process is still running
                $status = proc_get_status($process);
                if (!$status['running']) {
                    // Read any remaining output
                    while (!feof($stdout)) {
                        $line = fgets($stdout);
                        if ($line !== false) {
                            $output .= $line;
                            $this->parse_progress_line($line, $progress_id, $last_progress, $downloaded_bytes, $total_bytes, $speed, $has_started, $reached_100_percent);
                        }
                    }
                    while (!feof($stderr)) {
                        $line = fgets($stderr);
                        if ($line !== false) {
                            $output .= $line;
                            $this->parse_progress_line($line, $progress_id, $last_progress, $downloaded_bytes, $total_bytes, $speed, $has_started, $reached_100_percent);
                        }
                    }
                    break;
                }
                
                // Wait for data with timeout
                $changed = stream_select($read, $write, $except, 0, 200000); // 200ms timeout
                
                if ($changed > 0) {
                    // Read from stdout
                    if (in_array($stdout, $read)) {
                        while (($line = fgets($stdout)) !== false) {
                            $output .= $line;
                            $this->parse_progress_line($line, $progress_id, $last_progress, $downloaded_bytes, $total_bytes, $speed, $has_started, $reached_100_percent);
                        }
                    }
                    
                    // Read from stderr (where yt-dlp outputs progress)
                    if (in_array($stderr, $read)) {
                        while (($line = fgets($stderr)) !== false) {
                            $output .= $line;
                            $this->parse_progress_line($line, $progress_id, $last_progress, $downloaded_bytes, $total_bytes, $speed, $has_started, $reached_100_percent);
                        }
                    }
                }
            }
            
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } else {
            // Fallback to popen if proc_open fails
            $process = popen($command . ' 2>&1', 'r'); // Redirect stderr to stdout
        if ($process) {
                    while (!feof($process)) {
                        $line = fgets($process);
                        if ($line !== false) {
                            $output .= $line;
                            $this->parse_progress_line($line, $progress_id, $last_progress, $downloaded_bytes, $total_bytes, $speed, $has_started, $reached_100_percent);
                        }
                    }
                pclose($process);
            }
        }
        
        return $output;
    }
    
    private function parse_progress_line(&$line, $progress_id, &$last_progress, &$downloaded_bytes, &$total_bytes, &$speed, &$has_started, &$reached_100_percent) {
        if (empty(trim($line))) {
            return;
        }
        
        // Try to parse JSON progress output first (more accurate)
        $line_trimmed = trim($line);
        if (!empty($line_trimmed) && ($line_trimmed[0] === '{' || strpos($line_trimmed, 'downloaded') !== false)) {
            $json_data = json_decode($line_trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($json_data['downloaded'])) {
                $downloaded_bytes = isset($json_data['downloaded']) ? intval($json_data['downloaded']) : 0;
                $total_bytes = isset($json_data['total']) ? intval($json_data['total']) : 0;
                $speed = isset($json_data['speed']) ? intval($json_data['speed']) : 0;
                
                // Calculate percentage from bytes
                if ($total_bytes > 0) {
                    $progress = ($downloaded_bytes / $total_bytes) * 100;
                    // Only update if progress increased or we haven't reached 100% yet
                    if ($progress >= $last_progress || !$reached_100_percent) {
                        // If we've reached 100%, mark it and don't go backwards
                        if ($progress >= 100) {
                            $reached_100_percent = true;
                            $progress = 100; // Ensure it's exactly 100
                        }
                        
                        // Don't allow going backwards from 100%
                        if ($reached_100_percent && $progress < 100) {
                            $progress = 100;
                        }
                        
                        $last_progress = $progress;
                        $has_started = true;
                        $speed_formatted = $this->format_bytes($speed) . '/s';
                        $downloaded_formatted = $this->format_bytes($downloaded_bytes);
                        $total_formatted = $this->format_bytes($total_bytes);
                        
                        set_transient('ytdlp_progress_' . $progress_id, array(
                            'status' => 'downloading',
                            'progress' => $progress,
                            'percent' => $progress,
                            'message' => sprintf('Downloading... %s / %s (%s)', $downloaded_formatted, $total_formatted, $speed_formatted),
                            'downloaded_bytes' => $downloaded_bytes,
                            'total_bytes' => $total_bytes,
                            'speed' => $speed
                        ), 3600);
                    }
                }
                return; // Skip other parsing if JSON was successful
            }
        }
        
        // Parse progress from yt-dlp text output - multiple patterns for better coverage
        // Pattern 1: [download]  12.5% of 123.45MiB at 5.67MiB/s ETA 00:10
        // Pattern 2: [download]  12.5%
        // Pattern 3: [download] 100% of 123.45MiB
        if (preg_match('/\[download\]\s+(\d+\.?\d*)%\s*(?:of\s+([\d.]+)\s*([KMGT]?i?B))?/i', $line, $matches)) {
                    $progress = floatval($matches[1]);
            
            // If we've reached 100%, mark it
            if ($progress >= 100) {
                $reached_100_percent = true;
                $progress = 100; // Ensure it's exactly 100
            }
            
            // Only update if progress increased or we haven't reached 100% yet
            // Never go backwards from 100%
            if (($progress >= $last_progress && !$reached_100_percent) || ($reached_100_percent && $progress >= 100)) {
                // Don't allow going backwards from 100%
                if ($reached_100_percent && $progress < 100) {
                    $progress = 100;
                }
                
                        $last_progress = $progress;
                $has_started = true;
                
                // Try to extract file size if available
                if (isset($matches[2]) && isset($matches[3])) {
                    $size_value = floatval($matches[2]);
                    $size_unit = strtoupper($matches[3]);
                    $multiplier = 1;
                    if (strpos($size_unit, 'K') !== false) $multiplier = 1024;
                    elseif (strpos($size_unit, 'M') !== false) $multiplier = 1024 * 1024;
                    elseif (strpos($size_unit, 'G') !== false) $multiplier = 1024 * 1024 * 1024;
                    elseif (strpos($size_unit, 'T') !== false) $multiplier = 1024 * 1024 * 1024 * 1024;
                    $total_bytes = intval($size_value * $multiplier);
                    if ($total_bytes > 0 && $progress > 0) {
                        $downloaded_bytes = intval(($progress / 100) * $total_bytes);
                    }
                }
                
                // Try to extract speed
                if (preg_match('/at\s+([\d.]+)\s*([KMGT]?i?B)\/s/i', $line, $speed_matches)) {
                    $speed_value = floatval($speed_matches[1]);
                    $speed_unit = strtoupper($speed_matches[2]);
                    $speed_multiplier = 1;
                    if (strpos($speed_unit, 'K') !== false) $speed_multiplier = 1024;
                    elseif (strpos($speed_unit, 'M') !== false) $speed_multiplier = 1024 * 1024;
                    elseif (strpos($speed_unit, 'G') !== false) $speed_multiplier = 1024 * 1024 * 1024;
                    $speed = intval($speed_value * $speed_multiplier);
                }
                
                        set_transient('ytdlp_progress_' . $progress_id, array(
                    'status' => $reached_100_percent ? 'processing' : 'downloading',
                            'progress' => $progress,
                            'percent' => $progress,
                    'message' => $reached_100_percent ? 'Processing file...' : 'Downloading... ' . number_format($progress, 1) . '%',
                    'downloaded_bytes' => $downloaded_bytes,
                    'total_bytes' => $total_bytes,
                    'speed' => $speed
                ), 3600);
            }
            return; // Don't check processing stages if we just parsed download progress
        }
        
        // Check for processing stages - but only if we haven't reached 100% yet, or maintain 100%
        if ($reached_100_percent) {
            // Once we've reached 100%, processing stages should maintain 100% or high value
            if (strpos($line, '[ExtractAudio]') !== false || strpos($line, 'Extracting audio') !== false) {
                set_transient('ytdlp_progress_' . $progress_id, array(
                    'status' => 'processing',
                    'progress' => 100,
                    'percent' => 100,
                    'message' => 'Extracting audio...',
                    'downloaded_bytes' => $downloaded_bytes,
                    'total_bytes' => $total_bytes,
                    'speed' => 0
                ), 3600);
            } elseif (strpos($line, 'Merging formats') !== false || strpos($line, '[Merger]') !== false) {
                set_transient('ytdlp_progress_' . $progress_id, array(
                    'status' => 'processing',
                    'progress' => 100,
                    'percent' => 100,
                    'message' => 'Merging video and audio...',
                    'downloaded_bytes' => $downloaded_bytes,
                    'total_bytes' => $total_bytes,
                    'speed' => 0
                ), 3600);
            } elseif (strpos($line, 'Deleting original file') !== false || strpos($line, 'Post-processing') !== false) {
                set_transient('ytdlp_progress_' . $progress_id, array(
                    'status' => 'processing',
                    'progress' => 100,
                    'percent' => 100,
                    'message' => 'Finalizing...',
                    'downloaded_bytes' => $downloaded_bytes,
                    'total_bytes' => $total_bytes,
                    'speed' => 0
                        ), 3600);
                    }
        } else {
            // Before reaching 100%, show processing stages with appropriate progress
            if (strpos($line, '[ExtractAudio]') !== false || strpos($line, 'Extracting audio') !== false) {
                // Only update if current progress is less than 85%
                if ($last_progress < 85) {
                    set_transient('ytdlp_progress_' . $progress_id, array(
                        'status' => 'processing',
                        'progress' => 85,
                        'percent' => 85,
                        'message' => 'Extracting audio...',
                        'downloaded_bytes' => $downloaded_bytes,
                        'total_bytes' => $total_bytes,
                        'speed' => 0
                    ), 3600);
                }
                } elseif (strpos($line, 'Merging formats') !== false || strpos($line, '[Merger]') !== false) {
                // Only update if current progress is less than 90%
                if ($last_progress < 90) {
                    set_transient('ytdlp_progress_' . $progress_id, array(
                        'status' => 'processing',
                        'progress' => 90,
                        'percent' => 90,
                        'message' => 'Merging video and audio...',
                        'downloaded_bytes' => $downloaded_bytes,
                        'total_bytes' => $total_bytes,
                        'speed' => 0
                    ), 3600);
                }
            }
        }
        
        if (!$has_started && (strpos($line, '[download]') !== false || strpos($line, 'Downloading') !== false)) {
            // Mark as started when we see download activity
            $has_started = true;
            set_transient('ytdlp_progress_' . $progress_id, array(
                'status' => 'downloading',
                'progress' => 0,
                'percent' => 0,
                'message' => 'Starting download...',
                'downloaded_bytes' => 0,
                'total_bytes' => 0,
                'speed' => 0
            ), 3600);
        }
    }
    
    private function format_bytes($bytes) {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2) . ' MB';
        } else {
            return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        }
    }
    
    public function process_download_background($url, $format, $quality, $progress_id, $title = '') {
        // If title is empty, try to get it from video info
        if (empty($title)) {
            $video_info = $this->get_video_info($url);
            if (!is_wp_error($video_info) && isset($video_info['title'])) {
                $title = $video_info['title'];
            }
        }
        
        $result = $this->download_video($url, $format, $quality, $progress_id, $title);
        
        if (is_wp_error($result)) {
            set_transient('ytdlp_progress_' . $progress_id, array(
                'status' => 'error',
                'progress' => 0,
                'percent' => 0,
                'message' => $result->get_error_message()
            ), 3600);
        } else {
            set_transient('ytdlp_progress_' . $progress_id, array(
                'status' => 'complete',
                'progress' => 100,
                'percent' => 100,
                'message' => 'Download complete!',
                'download_url' => $result['download_url'],
                'file_name' => $result['file_name'],
                'file_size' => $result['file_size']
            ), 3600);
        }
    }
    
    private function get_video_info($url) {
        $settings = get_option('ytdlp_settings');
        $ytdlp_path = isset($settings['ytdlp_path']) ? $settings['ytdlp_path'] : '/usr/local/bin/yt-dlp';
        
        if (!file_exists($ytdlp_path)) {
            return new WP_Error('ytdlp_not_found', 'yt-dlp not found at specified path');
        }
        
        // Sanitize URL
        $url = escapeshellarg($url);
        
        // Get video info as JSON
        $cookies_param = '';
        if (isset($settings['cookies_file']) && !empty($settings['cookies_file']) && file_exists($settings['cookies_file'])) {
            $cookies_param = '--cookies ' . escapeshellarg($settings['cookies_file']);
            if (isset($settings['enable_logging']) && $settings['enable_logging']) {
                error_log('YT-DLP: Using cookies file: ' . $settings['cookies_file']);
            }
        } else {
            if (isset($settings['enable_logging']) && $settings['enable_logging']) {
                error_log('YT-DLP: No cookies file configured or file not found');
            }
        }
        
        $command = sprintf(
            '%s --dump-json --no-warnings --extractor-args "youtube:player_client=default" %s %s 2>&1',
            escapeshellarg($ytdlp_path),
            $cookies_param,
            $url
        );
        
        $output = shell_exec($command);
        $info = json_decode($output, true);
        
        if (empty($info)) {
            // Log the command and output for debugging
            if (isset($settings['enable_logging']) && $settings['enable_logging']) {
                error_log('YT-DLP Info Command: ' . $command);
                error_log('YT-DLP Info Output: ' . $output);
            }
            
            // Provide user-friendly error message
            $error_message = 'Could not retrieve video information. ';
            if (strpos($output, 'ERROR') !== false || strpos($output, 'error') !== false) {
                if (strpos($output, 'Private video') !== false) {
                    $error_message = 'This video is private and cannot be downloaded.';
                } elseif (strpos($output, 'Video unavailable') !== false) {
                    $error_message = 'This video is unavailable.';
                } elseif (strpos($output, 'Sign in') !== false || strpos($output, 'authentication') !== false) {
                    $error_message = 'Authentication required. Please check your cookies file settings.';
                } else {
                    $error_message = 'Failed to access video. Please check the URL and try again.';
                }
            } else {
                $error_message = 'Invalid video URL or video not accessible.';
            }
            
            return new WP_Error('invalid_video', $error_message);
        }
        
        // Extract relevant information
        $video_info = array(
            'title' => isset($info['title']) ? sanitize_text_field($info['title']) : 'Unknown',
            'duration' => isset($info['duration']) ? intval($info['duration']) : 0,
            'thumbnail' => isset($info['thumbnail']) ? esc_url($info['thumbnail']) : '',
            'uploader' => isset($info['uploader']) ? sanitize_text_field($info['uploader']) : 'Unknown',
            'formats' => array(),
            'available_qualities' => array()
        );
        
        // Get available formats and extract unique video heights
        $available_heights = array();
        if (isset($info['formats']) && is_array($info['formats'])) {
            foreach ($info['formats'] as $format) {
                if (isset($format['format_id']) && isset($format['ext'])) {
                    $video_info['formats'][] = array(
                        'format_id' => $format['format_id'],
                        'ext' => $format['ext'],
                        'resolution' => isset($format['resolution']) ? $format['resolution'] : 'audio only',
                        'filesize' => isset($format['filesize']) ? $format['filesize'] : 0
                    );
                    
                    // Extract video height for quality options
                    if (isset($format['height']) && is_numeric($format['height']) && $format['height'] > 0) {
                        $height = intval($format['height']);
                        // Only add video heights (not audio-only formats)
                        if (!isset($format['vcodec']) || $format['vcodec'] !== 'none') {
                            $available_heights[$height] = $height;
                        }
                    }
                }
            }
        }
        
        // Sort available heights in descending order and create quality options
        if (!empty($available_heights)) {
            krsort($available_heights);
            $video_info['available_qualities'] = array_values($available_heights);
        }
        
        return $video_info;
    }
    
    private function download_video($url, $format, $quality, $progress_id = null, $title = '') {
        $settings = get_option('ytdlp_settings');
        $ytdlp_path = isset($settings['ytdlp_path']) ? $settings['ytdlp_path'] : '/usr/local/bin/yt-dlp';
        $timeout = isset($settings['download_timeout']) ? $settings['download_timeout'] : 300;
        
        if (!file_exists($ytdlp_path)) {
            return new WP_Error('ytdlp_not_found', 'yt-dlp not found at specified path');
        }
        
        // Create temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/yt-dlp-downloads/temp';
        
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        // Sanitize title for filename if provided
        $sanitized_title = '';
        if (!empty($title)) {
            // Remove special characters and sanitize for filesystem
            $sanitized_title = sanitize_file_name($title);
            // Replace hyphens back to spaces (sanitize_file_name converts spaces to hyphens)
            $sanitized_title = str_replace('-', ' ', $sanitized_title);
            // Remove any remaining problematic characters, but keep spaces
            $sanitized_title = preg_replace('/[^a-zA-Z0-9_\s-]/', '', $sanitized_title);
            // Limit length to avoid filesystem issues
            $sanitized_title = substr($sanitized_title, 0, 200);
        }
        
        // Use sanitized title as the base filename, add number suffix if file exists
        if (!empty($sanitized_title)) {
            $base_filename = $sanitized_title . '.' . $format;
            $output_filename = $base_filename;
            $counter = 1;
            // If file exists, add a number suffix
            while (file_exists($temp_dir . '/' . $output_filename)) {
                $output_filename = $sanitized_title . '_' . $counter . '.' . $format;
                $counter++;
                // Safety limit to prevent infinite loop
                if ($counter > 1000) {
                    // Fallback to unique ID if too many conflicts
                    $output_filename = $sanitized_title . '_' . uniqid('', true) . '.' . $format;
                    break;
                }
            }
        } else {
            // Fallback to unique ID if no title provided
            $output_filename = uniqid('ytdlp_', true) . '.' . $format;
        }
        
        $output_template = $temp_dir . '/' . $output_filename;
        
        // Build command
        $url = escapeshellarg($url);
        
        // Add cookies parameter if available
        $cookies_param = '';
        if (isset($settings['cookies_file']) && !empty($settings['cookies_file']) && file_exists($settings['cookies_file'])) {
            $cookies_param = '--cookies ' . escapeshellarg($settings['cookies_file']);
            if (isset($settings['enable_logging']) && $settings['enable_logging']) {
                error_log('YT-DLP: Using cookies file for download: ' . $settings['cookies_file']);
            }
        }
        
        if ($format === 'mp3' || $format === 'm4a' || $format === 'wav' || $format === 'flac') {
            // Audio only - extract and convert
            // Handle audio quality: 'best' -> '0', numeric bitrate values -> 'K' suffix, 'worst' -> '9', default -> '5'
            if ($quality === 'best') {
                $audio_quality = '0';
            } elseif ($quality === 'worst') {
                $audio_quality = '9';
            } elseif (is_numeric($quality)) {
                // Numeric bitrate value (e.g., 256, 192) - add 'K' suffix for CBR
                $audio_quality = $quality . 'K';
            } else {
                $audio_quality = '5';
            }
            
            // Add FFmpeg path if specified
            $ffmpeg_param = '';
            if (isset($settings['ffmpeg_path']) && !empty($settings['ffmpeg_path']) && file_exists($settings['ffmpeg_path'])) {
                $ffmpeg_param = '--ffmpeg-location ' . escapeshellarg($settings['ffmpeg_path']);
            }
            
            // Use title-based filename for audio output template
            $audio_output_template = $output_template;
            
            // --- CHANGE 1: Added --concurrent-fragments 4 for faster download/processing ---
            $command = sprintf(
                '%s --concurrent-fragments 4 -x --audio-format %s --audio-quality %s --extractor-args "youtube:player_client=default" %s %s -o "%s" %s 2>&1',
                escapeshellarg($ytdlp_path),
                escapeshellarg($format),
                $audio_quality,
                $cookies_param,
                $ffmpeg_param,
                $audio_output_template,
                $url
            );
        } else {
            // Video with audio - handle different quality options
            // Note: MKV, webm, flv are container formats that require merging video+audio
            // MP4 can sometimes be direct, but may also need merging
            
            // Container formats that always need merging
            $container_formats = array('mkv', 'webm', 'flv');
            $is_container = in_array(strtolower($format), $container_formats);
            
            if ($is_container || $format === 'mp4') {
                // For container formats or MP4, download best video + best audio and merge
            if ($quality === 'best') {
                    $format_spec = 'bestvideo+bestaudio/best';
            } elseif ($quality === 'worst') {
                    $format_spec = 'worstvideo+worstaudio/worst';
                } else {
                    // Specific resolution (e.g., 1080p, 720p)
                    // Extract numeric height value (remove 'p' suffix if present)
                    $height = intval(preg_replace('/[^0-9]/', '', $quality));
                    $format_spec = sprintf('bestvideo[height<=%d]+bestaudio/best[height<=%d]/bestvideo+bestaudio', $height, $height);
                }
            } else {
                // For other formats, try direct first, then fallback to merge
                if ($quality === 'best') {
                    $format_spec = 'best[ext=' . $format . ']/bestvideo+bestaudio/best';
                } elseif ($quality === 'worst') {
                    $format_spec = 'worst[ext=' . $format . ']/worstvideo+worstaudio/worst';
                } else {
                    // Specific resolution
                    // Extract numeric height value (remove 'p' suffix if present)
                    $height = intval(preg_replace('/[^0-9]/', '', $quality));
                    $format_spec = sprintf('best[height<=%d][ext=%s]/bestvideo[height<=%d]+bestaudio/best[height<=%d]', $height, $format, $height, $height);
                }
            }
            
            // --- CHANGE 2: Added --concurrent-fragments 4 for faster download/merging ---
            $command = sprintf(
                '%s -f %s --concurrent-fragments 4 --merge-output-format %s --extractor-args "youtube:player_client=default" %s -o %s %s 2>&1',
                escapeshellarg($ytdlp_path),
                escapeshellarg($format_spec),
                escapeshellarg($format),
                $cookies_param,
                escapeshellarg($output_template),
                $url
            );
        }
        
        // Execute download with real-time progress
        if ($progress_id) {
            $output = $this->execute_with_progress($command, $progress_id);
        } else {
            $output = shell_exec($command);
        }
        
        // Look for the downloaded file (works for both audio and video)
        $expected_file = $temp_dir . '/' . $output_filename;
        if (file_exists($expected_file)) {
            $files = array($expected_file);
        } else {
            // Fallback: look for files with the title pattern or by extension
            $search_patterns = array();
            if (!empty($sanitized_title)) {
                // Try to find file with title pattern (with or without number suffix)
                $search_patterns[] = $temp_dir . '/' . $sanitized_title . '.' . $format;
                $search_patterns[] = $temp_dir . '/' . $sanitized_title . '_*.' . $format;
                $search_patterns[] = $temp_dir . '/' . $sanitized_title . '.*';
            }
            // Also search by format extension as last resort
            $search_patterns[] = $temp_dir . '/*.' . $format;
            
            $files = array();
            foreach ($search_patterns as $pattern) {
                $found = glob($pattern);
                if (!empty($found)) {
                    $files = array_merge($files, $found);
                }
            }
            
            // Filter out temp/part files and filter by format for audio
            $files = array_filter($files, function($file) use ($format, $temp_dir) {
                // Exclude temp/part files
                if (preg_match('/\.(part|temp|tmp)$/i', $file)) {
                    return false;
                }
                // Make sure it's in the temp directory
                if (strpos($file, $temp_dir) !== 0) {
                    return false;
                }
                // For audio formats, ensure the extension matches
                if (in_array($format, array('mp3', 'm4a', 'wav', 'flac'))) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    return in_array($ext, array('mp3', 'm4a', 'wav', 'flac', 'aac', 'ogg'));
                }
                return true;
            });
            
            // Sort by modification time (newest first) and prefer files with title in name
            usort($files, function($a, $b) use ($sanitized_title) {
                $a_has_title = !empty($sanitized_title) && strpos(basename($a), $sanitized_title) !== false;
                $b_has_title = !empty($sanitized_title) && strpos(basename($b), $sanitized_title) !== false;
                
                if ($a_has_title && !$b_has_title) return -1;
                if (!$a_has_title && $b_has_title) return 1;
                
                // Both have or don't have title, sort by modification time
                return filemtime($b) - filemtime($a);
            });
            
            // Remove duplicates and reindex
            $files = array_values(array_unique($files));
        }
        
        if (empty($files)) {
            if (isset($settings['enable_logging']) && $settings['enable_logging']) {
                error_log('YT-DLP Download failed: ' . $output);
            }
            return new WP_Error('download_failed', 'Download failed: ' . $output);
        }
        
        $file_path = $files[0];
        $file_size = filesize($file_path);
        
        // Check file size
        $max_file_size = isset($settings['max_file_size']) ? $settings['max_file_size'] : 500;
        if ($file_size > ($max_file_size * 1024 * 1024)) {
            unlink($file_path);
            return new WP_Error('file_too_large', 'File exceeds maximum allowed size');
        }
        
        // Get the actual file name
        $file_name = basename($file_path);
        $display_filename = $file_name;
        
        // If we have a sanitized title, always use it for the download filename
        if (!empty($sanitized_title)) {
            $clean_filename = $sanitized_title . '.' . $format;
            $clean_file_path = $temp_dir . '/' . $clean_filename;
            
            // Always try to rename to clean filename for consistency
            if ($file_name !== $clean_filename) {
                // Remove old clean file if it exists (from previous failed download)
                if (file_exists($clean_file_path) && $clean_file_path !== $file_path) {
                    @unlink($clean_file_path);
                }
                
                // Rename current file to clean name
                if (@rename($file_path, $clean_file_path)) {
                    $file_path = $clean_file_path;
                    $file_name = $clean_filename;
                    $display_filename = $clean_filename;
                } else {
                    // Rename failed, but always use clean name for display/download
                    $display_filename = $clean_filename;
                }
            } else {
                // File already has clean name
                $display_filename = $clean_filename;
            }
        }
        
        // Use actual file name for token (for security), but display_name for download
        $token = $this->generate_download_token($file_name);
        
        $download_url = add_query_arg(array(
            'ytdlp_download' => '1',
            'file' => $file_name,
            'token' => $token,
            'display_name' => urlencode($display_filename)
        ), site_url('/'));
        
        // Update progress - complete
        if ($progress_id) {
            set_transient('ytdlp_progress_' . $progress_id, array(
                'status' => 'complete',
                'progress' => 100,
                'percent' => 100,
                'message' => 'Download complete!'
            ), 3600);
        }
        
        // Schedule file cleanup after 1 hour
        wp_schedule_single_event(time() + 3600, 'ytdlp_cleanup_file', array($file_path));
        
        return array(
            'download_url' => str_replace('&amp;', '&', $download_url),
            'file_name' => $display_filename,
            'file_size' => $file_size
        );
    }
    
    private function generate_download_token($file_name) {
        return wp_hash($file_name . time(), 'nonce');
    }
    
    public function verify_download_token($file_name, $token) {
        // Token is valid for 1 hour
        $expected_token = wp_hash($file_name . time(), 'nonce');
        return hash_equals($expected_token, $token);
    }
}

// Handle direct download requests
add_action('init', function() {
    if (isset($_GET['ytdlp_download']) && isset($_GET['file']) && isset($_GET['token'])) {
        $file_name = sanitize_file_name($_GET['file']);
        $token = sanitize_text_field($_GET['token']);
        $display_name = isset($_GET['display_name']) ? sanitize_file_name(urldecode($_GET['display_name'])) : $file_name;
        
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'yt-dlp-downloads' . DIRECTORY_SEPARATOR . 'temp';
        $file_path = $temp_dir . DIRECTORY_SEPARATOR . $file_name;
        
        // If file with (ext)s template name doesn't exist, try to find the actual file
        if (!file_exists($file_path) && strpos($file_name, '(ext)s') !== false) {
            // Extract unique ID from filename
            $unique_id = preg_replace('/\.(ext)s.*$/', '', $file_name);
            $files = glob($temp_dir . DIRECTORY_SEPARATOR . $unique_id . '.*');
            
            if (!empty($files)) {
                $file_path = $files[0];
                $file_name = basename($file_path);
            }
        }
        
        if (!file_exists($file_path)) {
            wp_die('File not found or has expired');
        }
        
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Get file extension for proper MIME type
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $mime_types = array(
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac'
        );
        
        $content_type = isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';
        
        // Use display name (video title) for download filename - always prefer display_name
        $download_filename = !empty($display_name) ? $display_name : $file_name;
        
        // Properly escape filename for Content-Disposition header
        // Remove any quotes and escape special characters
        $download_filename = str_replace(array('"', "\r", "\n"), '', $download_filename);
        
        // Send headers
        header('Content-Type: ' . $content_type);
        // Use both filename (for basic compatibility) and filename* (for UTF-8 support)
        $encoded_filename = rawurlencode($download_filename);
        header('Content-Disposition: attachment; filename="' . $download_filename . '"; filename*=UTF-8\'\'' . $encoded_filename);
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Send file
        readfile($file_path);
        exit;
    }
}, 1);

// Cleanup scheduled files
add_action('ytdlp_cleanup_file', function($file_path) {
    if (file_exists($file_path)) {
        unlink($file_path);
    }
});