# Sushant Media Scanner

![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/License-GPLv2-green.svg)
![Version](https://img.shields.io/badge/Version-1.2.0-orange.svg)

A production-ready WordPress plugin engineered to accurately detect media usage across complex page builder architectures, custom fields, and standard post content. 

## The Problem
Traditional media cleanup plugins rely on simple string matching within the `post_content` column. When images are embedded via page builders like Elementor, stored in custom fields, or used as featured images, these standard scanners falsely flag the media as "unused." Cleaning up a library based on these false positives results in broken layouts and missing images.

## The Solution
Sushant Media Scanner utilizes a deep-parsing, multi-pass architecture. It extracts and cross-references metadata from multiple database tables (`wp_posts`, `wp_postmeta`, `wp_options`) to build an accurate dependency graph for every asset in your library.

## Technical Architecture
The plugin is built using a modular, object-oriented approach adhering to modern PHP standards:
- `Smscan_Core`: Handles database schema migrations and option management.
- `Smscan_Scanner`: The parsing engine handling Elementor JSON decoding and relational database queries.
- `Smscan_Ajax`: Manages batched AJAX endpoints to bypass PHP execution timeouts on large libraries.
- `Smscan_Admin`: Manages UI rendering, custom media columns, and settings.
- `Smscan_Telemetry`: Implements opt-in, anonymous telemetry using `wp_remote_post`.

## Core Features

- **Elementor Deep-Scan:** Decodes `_elementor_data` JSON recursively to locate attachment IDs embedded within widget configurations.
- **Multi-Usage Detection:** Accurately captures and displays every instance of an asset across multiple posts, rather than stopping at the first match.
- **Individual Asset Processing:** Provides a manual "Scan Now" trigger for individual media rows, allowing for instant verification without running a full library sweep.
- **Batched AJAX Engine:** Processes media in configurable batches (default 50) to ensure stability on shared hosting environments.
- **Scan Telemetry & Logs:** Maintains an internal log of the last 10 scan executions, detailing execution time and assets processed.
- **CSV Reporting:** Generates UTF-8 compliant CSV exports of the library's usage status.
- **WP-Cron Integration:** Supports automated background scans with configurable intervals.

## Screenshots

### Dashboard & Scan Logs
![Dashboard](https://ps.w.org/sushant-media-scanner/assets/screenshot-1.png?rev=3486108)

### Media Library Integration
![Media Library](https://ps.w.org/sushant-media-scanner/assets/screenshot-2.png?rev=3486108)

### Plugin Settings & Configuration
![Settings](https://ps.w.org/sushant-media-scanner/assets/screenshot-3.png?rev=3486108)

### Individual Asset Scanning
![Individual Scan](https://ps.w.org/sushant-media-scanner/assets/screenshot-4.png?rev=3486108)

### CSV Export
![CSV Export](https://ps.w.org/sushant-media-scanner/assets/screenshot-5.png?rev=3486108)

## Installation

### From WordPress Admin
1. Navigate to **Plugins > Add New**.
2. Search for `Sushant Media Scanner`.
3. Click **Install** and then **Activate**.

### Manual Installation
1. Download the repository as a `.zip` file.
2. Upload the extracted folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin menu.

## Privacy & Telemetry
This plugin collects zero personal data by default. 
If the site administrator explicitly opts in, a weekly anonymous payload is sent to a secure endpoint containing:
- Core version identifiers (WordPress, PHP, Plugin)
- Boolean flags for installed page builders (Elementor, Divi)
- Aggregate media statistics (total uploaded, total scanned)

No site URLs (a cryptographic hash is used instead), IP addresses, image URLs, or post content are ever transmitted. Opting out is available at any time within the plugin settings.

## Author
**Sushant Kumar**
- Website: [https://thesushant.in](https://thesushant.in)
- Portfolio: [https://thesushant.in/portfolio](https://thesushant.in/portfolio)
- LinkedIn: [https://linkedin.com/in/myselfsushaant](https://linkedin.com/in/myselfsushaant)

## License
This project is licensed under the GPL-2.0 License. See the [LICENSE](LICENSE) file for details.