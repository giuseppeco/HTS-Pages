# HTS Pages

WordPress plugin v0.4.0 — spreadsheet-like page property editing in **WP Admin → HTS Pages**.

## Features
- **Interactive Dependencies view**: select **View graph** on a page to inspect incoming references, the selected object, and outgoing links in a three-column layout. Click an object to navigate its own dependencies, with Back navigation and a direct view link. Read-only and permission-checked via authenticated AJAX.

- **Incoming / outgoing internal links**: clickable lists of source and target posts, pages, and public custom post types, shown per page. Scan HTML hyperlinks stored in post_content, index cached 15 minutes and automatically invalidated when posts are saved or deleted. Shows the number of scanned objects and warns when the 3,000-object limit is hit.

- **Page thumbnail**: a miniature live rendering of each page (lazy-loaded iframe). Click it to open a large live preview dialog; use the dialog link to open the page in a new tab.

- Paginated searchable inventory of WordPress pages; horizontal scroll, sticky identifiers, column chooser.
- All standard non-content `wp_posts` columns and operational fields visible; read-only system fields clearly marked.
- Inline editing for title, slug, status, author, dates, parent, ordering, excerpt, comment/ping settings, password, page template, featured image ID, Polylang language, assigned taxonomy slugs and permitted scalar postmeta.
- Supports Yoast/Rank Math SEO title and description fields when present.
- AJAX nonce, user capabilities, field validation, optimistic comparisons, no Gutenberg content mutation.

## Limits & safeguards
System/secret/serialized metadata is deliberately not exposed. Arbitrary internal keys cannot be edited. Language changes may affect Polylang associations. Dates must be entered as `YYYY-MM-DD HH:MM:SS`; taxonomy values use existing slugs separated by commas. Each save is immediate, including status changes on live pages; review carefully. No bulk changes in v0.4.0. The WordPress post content and filtered content fields are never requested or updated.

## Install
Download the GitHub repository ZIP, then in WordPress go to Plugins → Add New → Upload Plugin. The plugin header is at the archive root (`hts-pages.php`). Activate **HTS Pages**. Alternatively copy `hts-pages.php` into `wp-content/plugins/hts-pages/hts-pages.php`. WordPress 6.4+ and PHP 8.0+ recommended.

## Preview caveats
The preview shows the public URL, not unpublished Gutenberg revisions. Draft/private pages may display an access or not-found screen, and strict frame-blocking headers can prevent embedded display. Miniature frames are loaded lazily; large page lists may load many page views.

## Link detection scope
These columns are computed and read-only. They include internal hyperlinks found in saved post content, not external URLs, theme-rendered links, menu relationships, dynamically generated Query Loop results, redirects or links stored only in widget/plugin metadata. They do not map page parent relationships. The latest 3,000 content objects are scanned, so counts may be incomplete on larger sites. All target names/URLs shown are derived from WordPress post records; unpublished content visibility follows normal WordPress access controls.
