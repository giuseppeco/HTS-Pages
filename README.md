# HTS Pages

WordPress plugin v0.6.3 — spreadsheet-like page property editing in **WP Admin → HTS Pages**.

## Visual page map (v0.6.0)
Open **HTS Pages → Mappa pagine**. Each page appears once with its miniature, one description and two buttons:
- **Sequenza blocchi** shows every saved Gutenberg block in source order, its title and description (from associated TO BE cards when available), and clickable textual outgoing links to readable child pages. The checkbox can hide technical/structural blocks.
- **Percorsi ipotizzati** opens named ordered block sequences. Rename, add, remove and reorder blocks or entire paths, then **Salva percorsi**. Initial suggestions are hypotheses based on structure, never observed navigation. They are stored per page in `_htsp_map_paths_v1`, separately from page content. A changed source triggers a review warning; missing block references cannot be saved.

Directed arrows connect pages with hyperlinks in their saved content, including readable referenced patterns/navigation blocks. Multiple parents, shared children, cycles and disconnected pages are supported. They are not WordPress parent relationships. Search, status filters, direct-neighborhood focus, visual root, zoom, pan and lazy thumbnails help inspect the graph.

Click a miniature to edit page properties, including the complete Gutenberg source in an advanced section. New pages start as drafts. Removal uses the recoverable WordPress trash; restore is available after enabling the trash filter. Permanent deletion is never offered. Incoming links are not automatically removed when a page is trashed.

Nonce and per-page edit/read/delete/publish capabilities are enforced server-side. Saves compare a state token and use a short write lock. Protected, secret and structured metadata remain in their own editors; system fields are read-only. WordPress hooks remain authoritative; additional-property failures are reported as partial saves requiring reload, rather than claiming a transaction.

The map scans at most 1,000 pages and reports truncation. Header/footer/template links and generated Query Loop/shortcode/script links are excluded. Referenced pattern links are scanned, but the block sequence does not expand their internal structure. Draft/private miniature rendering depends on normal WordPress preview and frame permissions. The legacy table retains its own 3,000-object link index.

## Legacy table features
- **Linked-object thumbnails everywhere**: incoming and outgoing link lists in the table and dependency graph show a live miniature of each source or target. Clicking a thumbnail opens the full page preview; separate controls inspect the object's dependencies or open its public URL.

- **Interactive Dependencies view**: select **View graph** on a page to inspect incoming references, the selected object, and outgoing links in a three-column layout. Click an object to navigate its own dependencies, with Back navigation and a direct view link. Read-only and permission-checked via authenticated AJAX.

- **Incoming / outgoing internal links**: clickable lists of source and target posts, pages, and public custom post types, shown per page. Scan HTML hyperlinks stored in post_content, index cached 15 minutes and automatically invalidated when posts are saved or deleted. Shows the number of scanned objects and warns when the 3,000-object limit is hit.

- **Page thumbnail**: a miniature live rendering of each page (lazy-loaded iframe). Click it to open a large live preview dialog; use the dialog link to open the page in a new tab.

- Paginated searchable inventory of WordPress pages; horizontal scroll, sticky identifiers, column chooser.
- All standard non-content `wp_posts` columns and operational fields visible; read-only system fields clearly marked.
- Inline editing for title, slug, status, author, dates, parent, ordering, excerpt, comment/ping settings, password, page template, featured image ID, Polylang language, assigned taxonomy slugs and permitted scalar postmeta.
- Supports Yoast/Rank Math SEO title and description fields when present.
- AJAX nonce, user capabilities, field validation, optimistic comparisons, no Gutenberg content mutation.

## Limits & safeguards
System/secret/serialized metadata is deliberately not exposed. Arbitrary internal keys cannot be edited. Language changes may affect Polylang associations. Dates must be entered as `YYYY-MM-DD HH:MM:SS`; taxonomy values use existing slugs separated by commas. Each save is immediate, including status changes on live pages; review carefully. No bulk changes. The legacy table never edits page content; the visual map can change it only through its explicit advanced source field.

## Install
Download the GitHub repository ZIP, then in WordPress go to Plugins → Add New → Upload Plugin. The plugin header is at the archive root (`hts-pages.php`). Activate **HTS Pages**. Alternatively copy the whole plugin folder, including `includes/` and `assets/`, into `wp-content/plugins/hts-pages/`. WordPress 6.4+ and PHP 8.0+ recommended.

## Preview caveats
The preview shows the public URL, not unpublished Gutenberg revisions. Draft/private pages may display an access or not-found screen, and strict frame-blocking headers can prevent embedded display. Miniature frames are loaded lazily; large page lists may load many page views.

## Link detection scope
These columns are computed and read-only. They include internal hyperlinks found in saved post content, not external URLs, theme-rendered links, menu relationships, dynamically generated Query Loop results, redirects or links stored only in widget/plugin metadata. They do not map page parent relationships. The latest 3,000 content objects are scanned, so counts may be incomplete on larger sites. All target names/URLs shown are derived from WordPress post records; unpublished content visibility follows normal WordPress access controls.


## v0.6.1
Draft query permalinks no longer overwrite the canonical home URL in the graph index. Live CRUD and journey persistence are exercised with a dedicated disposable draft.

## v0.6.2
Trashed pages display their actual trash status in the disabled properties selector.

## v0.6.3
Four editorial link counters (incoming pages, outgoing posts, outgoing pages, external destinations) appear above each map preview. Pages with zero incoming links are prioritized as layout roots; each node's layout height is synchronized with the expanded counter panel. Note: counts are of distinct extracted URLs, not necessarily every repeated occurrence of an anchor in the content. The map is bounded by its 1,000-page scope.
