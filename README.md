# HTS Pages

WordPress plugin v0.1.0 — spreadsheet-like page property editing in **WP Admin → HTS Pages**.

## Features
- Paginated searchable inventory of WordPress pages; horizontal scroll, sticky identifiers, column chooser.
- All standard non-content `wp_posts` columns and operational fields visible; read-only system fields clearly marked.
- Inline editing for title, slug, status, author, dates, parent, ordering, excerpt, comment/ping settings, password, page template, featured image ID, Polylang language, assigned taxonomy slugs and permitted scalar postmeta.
- Supports Yoast/Rank Math SEO title and description fields when present.
- AJAX nonce, user capabilities, field validation, optimistic comparisons, no Gutenberg content mutation.

## Limits & safeguards
System/secret/serialized metadata is deliberately not exposed. Arbitrary internal keys cannot be edited. Language changes may affect Polylang associations. Dates must be entered as `YYYY-MM-DD HH:MM:SS`; taxonomy values use existing slugs separated by commas. Each save is immediate, including status changes on live pages; review carefully. No bulk changes in v0.1.0. The WordPress post content and filtered content fields are never requested or updated.

## Install
Copy `hts-pages/hts-pages.php` into `wp-content/plugins/hts-pages/hts-pages.php`, then activate. WordPress 6.4+ and PHP 8.0+ recommended.
