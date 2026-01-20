<?php
/**
 * Frontend class for YT-DLP Downloader
 */

class YTDLP_Frontend {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {

        add_shortcode('ytdlp_downloader', array($this, 'render_downloader'));

        // wpautop fix
        remove_filter('the_content', 'wpautop');
        add_filter('the_content', 'wpautop', 99);
        add_filter('the_content', array($this, 'prevent_wpautop_on_shortcode'), 98);
    }

    public function prevent_wpautop_on_shortcode($content) {
        if (has_shortcode($content, 'ytdlp_downloader')) {
            return $content;
        }
        return $content;
    }

    /**
     * SHORTCODE RENDER
     * Assets are enqueued HERE (100% reliable)
     */
    public function render_downloader($atts) {

        /* ===============================
         * ENQUEUE FRONTEND ASSETS
         * =============================== */
        wp_enqueue_style(
            'ytdlp-frontend',
            YTDLP_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            filemtime(YTDLP_PLUGIN_DIR . 'assets/css/frontend.css')
        );

        wp_enqueue_script(
            'ytdlp-frontend',
            YTDLP_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            filemtime(YTDLP_PLUGIN_DIR . 'assets/js/frontend.js'),
            true
        );

        wp_localize_script('ytdlp-frontend', 'ytdlpData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ytdlp_nonce')
        ));

        /* ===============================
         * SHORTCODE ATTRIBUTES
         * =============================== */
        $atts = shortcode_atts(array(
            'title'       => 'Video Downloader',
            'placeholder' => 'Enter YT Video URL'
        ), $atts);

        $settings = get_option('ytdlp_settings');

        $allowed_formats = isset($settings['allowed_formats']) && is_array($settings['allowed_formats'])
            ? $settings['allowed_formats']
            : array('mp4', 'webm', 'mkv', 'mp3', 'm4a');

        $video_formats = array_filter($allowed_formats, function ($f) {
            return in_array($f, ['mp4', 'webm', 'mkv', 'flv']);
        });

        $audio_formats = array_filter($allowed_formats, function ($f) {
            return in_array($f, ['mp3', 'm4a', 'aac', 'ogg', 'wav']);
        });

        ob_start();
        ?>
        <!-- YTDLP FRONTEND LOADED -->
        <div class="ytdlp-container">

            <?php if (!empty($atts['title'])): ?>
                <h2 class="ytdlp-title"><?php echo esc_html($atts['title']); ?></h2>
            <?php endif; ?>

            <div class="ytdlp-input-section">
                <input type="text" id="ytdlp-url-input" class="ytdlp-input"
                       placeholder="<?php echo esc_attr($atts['placeholder']); ?>">
                <button id="ytdlp-fetch-btn">Analyze</button>
            </div>

            <div id="ytdlp-messages" style="display:none;"></div>

            <div id="ytdlp-video-info" style="display:none;">
                <div class="ytdlp-info-header">
                    <img id="ytdlp-thumbnail" class="ytdlp-thumbnail" src="" alt="">
                    <div>
                        <h3 id="ytdlp-video-title"></h3>
                        <p id="ytdlp-uploader"></p>
                        <p id="ytdlp-duration"></p>
                    </div>
                </div>

                <div class="ytdlp-download-options">

                    <div class="ytdlp-tab-selector">
                        <button class="ytdlp-tab-btn active" data-tab="video">Video</button>
                        <button class="ytdlp-tab-btn" data-tab="audio">Audio</button>
                    </div>

                    <input type="hidden" id="ytdlp-format"
                           value="<?php echo esc_attr(reset($video_formats)); ?>">

                    <!-- VIDEO TAB -->
                    <div id="ytdlp-tab-video" class="ytdlp-tab-content active">
                        <div class="ytdlp-option-group">
                            <label>Format:</label>
                            <select id="ytdlp-video-format-user" class="ytdlp-select">
                                <?php foreach ($video_formats as $format): ?>
                                    <option value="<?php echo esc_attr($format); ?>">
                                        <?php echo strtoupper($format); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ytdlp-option-group">
                            <label>Video:</label>
                            <select id="ytdlp-quality" class="ytdlp-select">
                                <option value="1080p" selected>Default (1080p)</option>
                            </select>
                        </div>
                    </div>

                    <!-- AUDIO TAB -->
                    <div id="ytdlp-tab-audio" class="ytdlp-tab-content">
                        <div class="ytdlp-option-group">
                            <label>Format:</label>
                            <select id="ytdlp-audio-format-user" class="ytdlp-select">
                                <?php foreach ($audio_formats as $format): ?>
                                    <option value="<?php echo esc_attr($format); ?>">
                                        <?php echo strtoupper($format); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ytdlp-option-group">
                            <label>Audio:</label>
                            <select id="ytdlp-audio-quality" class="ytdlp-select">
                                <option value="256" selected>256 KBPS</option>
                                <option value="192">192 KBPS</option>
                                <option value="128">128 KBPS</option>
                            </select>
                        </div>
                    </div>

                    <button id="ytdlp-download-btn">
                        Start Downloading
                    </button>
                </div>
            </div>

            <!-- PROGRESS -->
            <div id="ytdlp-download-progress" style="display:none;">
                <div id="ytdlp-progress-bar-container">
                    <div id="ytdlp-progress-bar"></div>
                    <span id="ytdlp-progress-text">0%</span>
                </div>
            </div>

            <!-- READY -->
            <div id="ytdlp-download-ready" style="display:none;">
                <div class="ytdlp-success-icon">✓</div>
                <a id="ytdlp-download-link" class="ytdlp-btn-download" download>
                    Download File
                </a>
                <div class="ytdlp-file-info">
                    <p><strong>File: </strong><span id="ytdlp-file-name"></span></p><br>
                    <p><strong>Size:</strong> <span id="ytdlp-file-size"></span></p>
                </div>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}
