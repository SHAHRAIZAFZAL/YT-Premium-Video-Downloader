# Advanced Media Downloader & Processor (PHP)

A high-performance, custom-built web application designed to fetch, process, and download media from various platforms. This project focuses on handling long-running server tasks and providing a real-time user experience.

## 🚀 Key Features

* **Asynchronous Processing:** Utilizes background execution to handle heavy media downloads without blocking the web interface.
* **Real-time Progress Tracking:** A custom AJAX polling system that communicates with a PHP API to provide live status updates (0-100%).
* **Dynamic Format Selection:** Supports multiple video/audio qualities (MP4, MP3, etc.) with automatic bitrate detection.
* **Secure File Handling:** Implements regex-based filename sanitization and automated storage management.

## 🛠️ Technical Stack

* **Backend:** PHP (OOP), CLI Integration
* **Frontend:** JavaScript (jQuery), AJAX, HTML5, CSS3
* **Engine:** Powered by yt-dlp for reliable media extraction.


## 📸 How it Works

1.  The user inputs a media URL.
2.  The PHP backend initiates a background process via `shell_exec`.
3.  A temporary status file tracks the progress.
4.  The frontend polls the API every second to update the progress bar visually.
5.  Once complete, the file is sanitized and served for download.

---
Developed by Shahraiz