(function($) {
    'use strict';
    
    let currentVideoUrl = '';
    let progressTimer;
    let videoDuration = 0; // Store video duration in seconds
    let downloadStartTime = 0; // Track when download started
    let currentVideoTitle = ''; // Store video title for filename
    
    // Global constants for formats and selectors
    // NOTE: Audio formats are listed here to control the logic
    const audioFormats = ['mp3', 'm4a', 'aac', 'ogg', 'wav'];
    
    $(document).ready(function() {
        // Check if ytdlpData is loaded
        if (typeof ytdlpData === 'undefined') {
            console.error('ytdlpData is not defined. Make sure the plugin is properly loaded.');
            $('#ytdlp-messages').html('<div class="ytdlp-message ytdlp-message-error">Plugin configuration error. Please refresh the page.</div>');
            return;
        }
        
        initDownloader();
        // Initialize custom dropdowns
        initCustomDropdowns();
        // Initialize the default format on document ready
        if ($('#ytdlp-video-format-user').length) {
             $('#ytdlp-format').val($('#ytdlp-video-format-user').val());
        }
    });
    
    // Initialize custom dropdowns
    function initCustomDropdowns() {
        $('select.ytdlp-select').each(function() {
            const $select = $(this);
            // Check if custom dropdown already exists next to this select
            if ($select.next('.ytdlp-custom-select').length === 0) {
                createCustomDropdown($select);
            } else {
                // Update existing custom dropdown
                updateCustomDropdown($select);
            }
        });
    }

    // Update existing custom dropdown
    function updateCustomDropdown($select) {
        const $customSelect = $select.next('.ytdlp-custom-select');
        if ($customSelect.length === 0) return;
        
        const selectedOption = $select.find('option:selected');
        const selectedText = selectedOption.text();
        const $button = $customSelect.find('.ytdlp-custom-select-button');
        const $options = $customSelect.find('.ytdlp-custom-select-options');
        
        // Update button text
        let mainText = selectedText;
        let recommendedText = '';
        if (selectedText.includes('(Recommended)')) {
            mainText = selectedText.replace(' (Recommended)', '').replace('(Recommended)', '');
            recommendedText = ' (Recommended)';
        }
        $button.html(mainText + (recommendedText ? '<span class="recommended-text">' + recommendedText + '</span>' : ''));
        
        // Update options
        $options.empty();
        $select.find('option').each(function() {
            const $option = $(this);
            const optionText = $option.text();
            const optionValue = $option.val();
            const isRecommended = $option.attr('data-recommended') === 'true';
            const isSelected = $option.prop('selected');

            let mainText = optionText;
            let recommendedText = '';
            if (optionText.includes('(Recommended)')) {
                mainText = optionText.replace(' (Recommended)', '').replace('(Recommended)', '');
                recommendedText = ' (Recommended)';
            }

            const $customOption = $('<div>', {
                class: 'ytdlp-custom-select-option' + (isSelected ? ' selected' : ''),
                'data-value': optionValue
            });

            $customOption.html(mainText + (recommendedText ? '<span class="recommended-text">' + recommendedText + '</span>' : ''));

            $customOption.on('click', function() {
                $select.val(optionValue);
                $button.html(mainText + (recommendedText ? '<span class="recommended-text">' + recommendedText + '</span>' : ''));
                $options.find('.ytdlp-custom-select-option').removeClass('selected');
                $(this).addClass('selected');
                $customSelect.removeClass('open');
                setTimeout(function() {
                    $select.trigger('change');
                }, 10);
            });

            $options.append($customOption);
        });
    }

    // Create custom dropdown from native select
    function createCustomDropdown($select) {
        const selectId = $select.attr('id');
        const selectedOption = $select.find('option:selected');
        const selectedText = selectedOption.text();
        const selectedValue = selectedOption.val();

        // Create custom dropdown structure
        const $customSelect = $('<div>', { class: 'ytdlp-custom-select' });
        const $button = $('<div>', { class: 'ytdlp-custom-select-button' });
        const $options = $('<div>', { class: 'ytdlp-custom-select-options' });

        // Set button text
        $button.text(selectedText);

        // Create options
        $select.find('option').each(function() {
            const $option = $(this);
            const optionText = $option.text();
            const optionValue = $option.val();
            const isRecommended = $option.attr('data-recommended') === 'true';
            const isSelected = $option.prop('selected');

            // Split text if it contains "(Recommended)"
            let mainText = optionText;
            let recommendedText = '';
            if (optionText.includes('(Recommended)')) {
                mainText = optionText.replace(' (Recommended)', '').replace('(Recommended)', '');
                recommendedText = ' (Recommended)';
            }

            const $customOption = $('<div>', {
                class: 'ytdlp-custom-select-option' + (isSelected ? ' selected' : ''),
                'data-value': optionValue
            });

            $customOption.html(mainText + (recommendedText ? '<span class="recommended-text">' + recommendedText + '</span>' : ''));

            // Click handler
            $customOption.on('click', function() {
                // Update native select
                $select.val(optionValue);
                
                // Update button text
                $button.html(mainText + (recommendedText ? '<span class="recommended-text">' + recommendedText + '</span>' : ''));
                
                // Update selected state
                $options.find('.ytdlp-custom-select-option').removeClass('selected');
                $(this).addClass('selected');
                
                // Close dropdown
                $customSelect.removeClass('open');
                
                // Trigger change event after a short delay to ensure DOM is updated
                setTimeout(function() {
                    $select.trigger('change');
                }, 10);
            });

            $options.append($customOption);
        });

        // Button click handler
        $button.on('click', function(e) {
            e.stopPropagation();
            $customSelect.toggleClass('open');
        });

        // Close dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.ytdlp-custom-select').length) {
                $('.ytdlp-custom-select').removeClass('open');
            }
        });

        // Assemble custom dropdown
        $customSelect.append($button);
        $customSelect.append($options);

        // Replace native select
        $select.after($customSelect);
    }

    function initDownloader() {
        
        // Fetch video info button
        $('#ytdlp-fetch-btn').on('click', function() {
            const url = $('#ytdlp-url-input').val().trim();
            
            if (!url) {
                showMessage('error', 'Please enter a valid URL');
                return;
            }
            
            if (!isValidUrl(url)) {
                showMessage('error', 'Please enter a valid video URL');
                return;
            }
            
            fetchVideoInfo(url);
        });
        
        // Download button
        $('#ytdlp-download-btn').on('click', function() {
            // Read the format from the hidden field (updated by format selectors/tabs)
            const format = $('#ytdlp-format').val(); 
            
            let quality;
            
            // Determine which quality selector is currently relevant
            if (audioFormats.includes(format)) {
                quality = $('#ytdlp-audio-quality').val();
            } else {
                quality = $('#ytdlp-quality').val(); 
            }
            
            if (!quality || !format) {
                 showMessage('error', 'Please select a format and quality option.');
                 return;
            }
            
            downloadVideo(currentVideoUrl, format, quality);
        });
        
        // Enter key support
        $('#ytdlp-url-input').on('keypress', function(e) {
            if (e.which === 13) {
                $('#ytdlp-fetch-btn').trigger('click');
            }
        });

        // ----------------------------------------------------
        // TAB AND FORMAT LOGIC FOR NEW UI
        // ----------------------------------------------------

        // Handle tab switching (Video/Audio)
        $('.ytdlp-tab-btn').on('click', function() {
            const targetTab = $(this).data('tab');

            $('.ytdlp-tab-btn').removeClass('active');
            $(this).addClass('active');

            $('.ytdlp-tab-content').hide();
            $('#ytdlp-tab-' + targetTab).show();

            let currentFormat;
            if (targetTab === 'video') {
                currentFormat = $('#ytdlp-video-format-user').val();
            } else {
                currentFormat = $('#ytdlp-audio-format-user').val();
            }
            $('#ytdlp-format').val(currentFormat);
        });
        
        // Handle format selection within the tab (works with both native and custom dropdowns)
        $(document).on('change', '#ytdlp-video-format-user, #ytdlp-audio-format-user', function() {
            $('#ytdlp-format').val($(this).val());
            // Update custom dropdown if it exists
            const $customSelect = $(this).next('.ytdlp-custom-select');
            if ($customSelect.length) {
                const selectedText = $(this).find('option:selected').text();
                $customSelect.find('.ytdlp-custom-select-button').text(selectedText);
            }
        });
    }

    
    function fetchVideoInfo(url) {
        $('#ytdlp-fetch-btn').prop('disabled', true).html('<span class="ytdlp-spinner"></span> Analyzing...');
        hideAllSections();
        currentVideoUrl = url;
        showMessage('', ''); // Clear any previous messages
        
        $.ajax({
            url: ytdlpData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ytdlp_get_info',
                nonce: ytdlpData.nonce,
                url: url
            },
            success: function(response) {
                if (response.success) {
                    displayVideoInfo(response.data);
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : 'An error occurred';
                    showMessage('error', errorMsg);
                    console.error('Video info error:', response);
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = 'An error occurred';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                } else if (xhr.responseText) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        }
                    } catch(e) {
                        errorMsg = 'Network error: ' + error;
                    }
                }
                showMessage('error', errorMsg);
                console.error('AJAX error:', status, error, xhr);
            },
            complete: function() {
                $('#ytdlp-fetch-btn').prop('disabled', false).html('Analyze');
            }
        });
    }
    
    function displayVideoInfo(info) {
        $('#ytdlp-video-title').text(escapeHtml(info.title));
        $('#ytdlp-uploader').text('By: ' + escapeHtml(info.uploader));
        
        // Store video title for filename
        currentVideoTitle = info.title || '';
        
        // Store video duration for progress calculation
        videoDuration = info.duration && info.duration > 0 ? parseInt(info.duration) : 0;
        
        // Format duration nicely
        if (videoDuration > 0) {
            $('#ytdlp-duration').text('Duration: ' + formatDuration(videoDuration));
        } else {
            $('#ytdlp-duration').text('Duration: Unknown');
        }
        
        $('#ytdlp-thumbnail').attr('src', info.thumbnail || '');
        
        // Populate quality dropdown with available qualities
        populateQualityOptions(info.available_qualities || []);
        
        $('#ytdlp-video-info').show();
        showMessage('', ''); 
        
        $('.ytdlp-tab-btn').removeClass('active');
        $('[data-tab="video"]').addClass('active');
        $('.ytdlp-tab-content').hide();
        $('#ytdlp-tab-video').show();
        
        if ($('#ytdlp-video-format-user').length) {
             $('#ytdlp-format').val($('#ytdlp-video-format-user').val());
        }
        
        // Reinitialize custom dropdowns after quality options are populated
        setTimeout(function() {
            initCustomDropdowns();
        }, 100);
    }
    
    function populateQualityOptions(availableHeights) {
        const qualitySelect = $('#ytdlp-quality');
        const currentValue = qualitySelect.val();
        
        // Clear existing options
        qualitySelect.empty();
        
        // Add "Default" option with 1080p (recommended)
        qualitySelect.append('<option value="1080p" data-recommended="true">Default (1080p)</option>');
        
        // Add available quality options in descending order
        if (availableHeights && availableHeights.length > 0) {
            // Sort heights in descending order
            const sortedHeights = availableHeights.slice().sort(function(a, b) { return b - a; });
            
            sortedHeights.forEach(function(height) {
                const value = height + 'p';
                let label = height + 'p';
                
                // Mark 1080p as recommended if it exists
                if (height === 1080) {
                    label = height + 'p (Recommended)';
                    qualitySelect.append('<option value="' + value + '" data-recommended="true">' + label + '</option>');
                } else {
                    qualitySelect.append('<option value="' + value + '">' + label + '</option>');
                }
            });
        } else {
            // Fallback options if no heights provided
            qualitySelect.append('<option value="2160p">2160p</option>');
            qualitySelect.append('<option value="1440p">1440p</option>');
            qualitySelect.append('<option value="1080p" data-recommended="true">1080p (Recommended)</option>');
            qualitySelect.append('<option value="720p">720p</option>');
            qualitySelect.append('<option value="480p">480p</option>');
            qualitySelect.append('<option value="360p">360p</option>');
        }
        
        // Restore previous selection if it still exists, otherwise select recommended option
        if (currentValue && qualitySelect.find('option[value="' + currentValue + '"]').length > 0) {
            qualitySelect.val(currentValue);
        } else {
            // Try to select recommended option (data-recommended="true"), otherwise select first option
            const recommendedOption = qualitySelect.find('option[data-recommended="true"]');
            if (recommendedOption.length > 0) {
                qualitySelect.val(recommendedOption.first().val());
            } else {
                qualitySelect.prop('selectedIndex', 0);
            }
        }
        
        // Reinitialize custom dropdown after quality options are populated
        setTimeout(function() {
            initCustomDropdowns();
        }, 50);
    }
    
    function downloadVideo(url, format, quality) {
        $('#ytdlp-video-info').hide();
        $('#ytdlp-download-ready').hide();
        $('#ytdlp-download-progress').show();
        $('#ytdlp-download-btn').prop('disabled', true).html('Downloading...');
        showMessage('', ''); // Clear any previous messages
        
        // Record download start time
        downloadStartTime = Date.now();
        
        // Reset progress bar and start initial animation immediately
        $('#ytdlp-progress-bar').css('width', '0%');
        $('#ytdlp-progress-text').text('0%');
        
        // Start initial progress animation (shows activity while waiting for server response)
        let initialProgress = 0;
        const initialProgressTimer = setInterval(function() {
            if (initialProgress < 5) {
                initialProgress += 0.5;
                $('#ytdlp-progress-bar').css('width', initialProgress + '%');
                $('#ytdlp-progress-text').text(Math.round(initialProgress) + '%');
            } else {
                clearInterval(initialProgressTimer);
            }
        }, 100);
        
        $.ajax({
            url: ytdlpData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ytdlp_download',
                nonce: ytdlpData.nonce,
                url: url,
                format: format,
                quality: quality,
                title: currentVideoTitle
            },
            success: function(response) {
                clearInterval(initialProgressTimer);
                if (response.success && response.data.progress_id) {
                    startProgressPolling(response.data.progress_id);
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : 'An error occurred';
                    showMessage('error', errorMsg);
                    console.error('Download start error:', response);
                    $('#ytdlp-download-progress').hide();
                    $('#ytdlp-video-info').show();
                    $('#ytdlp-download-btn').prop('disabled', false).html('Start Downloading');
                }
            },
            error: function(xhr, status, error) {
                clearInterval(initialProgressTimer);
                let errorMsg = 'An error occurred';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                } else if (xhr.responseText) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        }
                    } catch(e) {
                        errorMsg = 'Network error: ' + error;
                    }
                }
                showMessage('error', errorMsg);
                console.error('Download AJAX error:', status, error, xhr);
                $('#ytdlp-download-progress').hide();
                $('#ytdlp-video-info').show();
                $('#ytdlp-download-btn').prop('disabled', false).html('Start Download');
            }
        });
    }
    
function startProgressPolling(progressId){
    clearInterval(progressTimer);
    if (window.ytdlpSmoothTimer) {
        clearInterval(window.ytdlpSmoothTimer);
    }
    
    $('#ytdlp-progress-bar-container').show();
    // Continue from where initial animation left off (should be around 5%)
    // Get current progress from the text display or start from 5%
    let currentPercent = parseInt($('#ytdlp-progress-text').text()) || 5;
    if (currentPercent < 5) currentPercent = 5; // Start from at least 5% if we're starting fresh
    
    let isComplete = false;
    let lastServerPercent = currentPercent;
    
    // Smooth progress animation - runs every 100ms
    // This provides smooth UI updates between server polls
    window.ytdlpSmoothTimer = setInterval(function() {
        if (isComplete) return;
        
        // Smoothly animate towards the last known server progress
        if (currentPercent < lastServerPercent) {
            const increment = Math.min((lastServerPercent - currentPercent) * 0.3, 2.0);
            currentPercent = Math.min(currentPercent + increment, lastServerPercent);
        } else if (currentPercent > lastServerPercent) {
            // If server progress is lower (shouldn't happen, but handle it)
            currentPercent = lastServerPercent;
        }
        
        // Update display
        $('#ytdlp-progress-bar').css('width', currentPercent + '%');
        $('#ytdlp-progress-text').text(Math.round(currentPercent) + '%');
    }, 100);
    
    // Poll server for completion (no nonce needed - progress_id is secure enough)
    function pollProgress() {
        $.ajax({
            url:ytdlpData.ajaxUrl,
            type:'POST',
            data:{action:'ytdlp_progress',progress_id:progressId},
            success:function(response){
                if(response.success){
                    const progress = response.data;
                    
                    // Update progress from server (use actual percent if available)
                    if (progress.percent !== undefined && progress.percent !== null) {
                        lastServerPercent = Math.min(Math.max(parseFloat(progress.percent), 0), 100);
                        // Don't allow progress to go backwards
                        if (lastServerPercent < currentPercent) {
                            lastServerPercent = currentPercent;
                        }
                    } else if (progress.progress !== undefined && progress.progress !== null) {
                        lastServerPercent = Math.min(Math.max(parseFloat(progress.progress), 0), 100);
                        // Don't allow progress to go backwards
                        if (lastServerPercent < currentPercent) {
                            lastServerPercent = currentPercent;
                        }
                    }
                    
                    // Handle completion
                    if(progress.status==='complete'){
                        isComplete = true;
                        clearInterval(progressTimer);
                        if (window.ytdlpSmoothTimer) {
                            clearInterval(window.ytdlpSmoothTimer);
                        }
                        
                        // Ensure we're at 100%
                        lastServerPercent = 100;
                        currentPercent = 100;
                        $('#ytdlp-progress-bar').css('width','100%');
                        $('#ytdlp-progress-text').text('100%');
                        
                        setTimeout(function(){
                            displayDownloadReady(progress.download_url, progress.file_name, progress.file_size);
                        }, 500);
                    } else if(progress.status==='error'){
                        isComplete = true;
                        clearInterval(progressTimer);
                        if (window.ytdlpSmoothTimer) {
                            clearInterval(window.ytdlpSmoothTimer);
                        }
                        showMessage('error',progress.message || 'Download failed');
                        $('#ytdlp-download-progress').hide();
                        $('#ytdlp-download-btn').prop('disabled',false);
                        $('#ytdlp-video-info').show();
                        $('#ytdlp-progress-bar-container').hide();
                    }
                } else {
                    // If progress not found, continue with last known progress
                    // Don't show error unless it's been a long time
                }
            },
            error:function(){
                // Silently fail - continue with last known progress
                // Only show error if download takes too long
            }
        });
    }
    
    // Poll every 2 seconds (less frequent, better performance)
    pollProgress();
    progressTimer = setInterval(pollProgress, 2000);
}

    function displayDownloadReady(downloadUrl, fileName, fileSize) {
        $('#ytdlp-download-progress').hide();
        
        $('#ytdlp-download-link')
            .attr('href', downloadUrl)
            .prop('download', fileName);
        
        $('#ytdlp-file-name').text(fileName);
        $('#ytdlp-file-size').text(formatFileSize(fileSize));
        
        $('#ytdlp-download-ready').show();
        showMessage('', ''); // Clear any messages
        $('#ytdlp-download-btn').prop('disabled', false).html('Start Download');
    }
    
    function showMessage(type, message) {
        const messagesDiv = $('#ytdlp-messages');
        messagesDiv.empty();
        
        if (message) {
            const className = type ? 'ytdlp-message ytdlp-message-' + type : 'ytdlp-message ytdlp-message-info';
            // Add loader for loading/info messages
            if (type === 'info' && message.toLowerCase().includes('loading')) {
                messagesDiv.html('<div class="' + className + '"><span class="ytdlp-message-spinner"></span>' + escapeHtml(message) + '</div>');
            } else {
                messagesDiv.html('<div class="' + className + '">' + escapeHtml(message) + '</div>');
            }
            messagesDiv.show();
        } else {
            messagesDiv.hide();
        }
    }
    
    function hideAllSections() {
        $('#ytdlp-video-info, #ytdlp-download-progress, #ytdlp-download-ready').hide();
    }
    
    function isValidUrl(string) {
        try {
            const url = new URL(string);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (_) {
            return false;
        }
    }
    
    function formatDuration(seconds) {
        if (!seconds) return 'Unknown';
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        if (hours > 0) {
            return hours + ':' + pad(minutes) + ':' + pad(secs);
        }
        return minutes + ':' + pad(secs);
    }
    
    function formatFileSize(bytes) {
        if (!bytes) return 'Unknown';
        const units = ['B', 'KB', 'MB', 'GB'];
        let size = bytes;
        let unitIndex = 0;
        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex++;
        }
        return size.toFixed(2) + ' ' + units[unitIndex];
    }
    
    function pad(num) {
        return num.toString().padStart(2, '0');
    }
    
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
})(jQuery);