<?php

declare(strict_types=1);

namespace CMS;

/**
 * WordPress + MetaWeblog XML-RPC server.
 *
 * Holds the request-scoped collaborators and site settings that the handlers
 * need, so the handler bodies can stay as they were rather than reaching for
 * globals. admin/xmlrpc.php is the front controller: it parses the request,
 * hands the method and params here, and prints whatever this echoes.
 *
 * Handlers echo their own response and `break`; faults are thrown as an
 * XmlRpcFault so the front controller can render them uniformly.
 */
class XmlRpcServer
{
    // ── ID space ─────────────────────────────────────────────────────────────
    // Posts and pages have separate auto-increment sequences, so IDs can
    // collide. The unified wp.*Post methods use large offsets for page and
    // media IDs so MarsEdit can tell them apart from post IDs.
    public const PAGE_ID_OFFSET  = 1000000;
    public const MEDIA_ID_OFFSET = 2000000;

    private string $siteUrl;
    private string $siteTitle;
    private string $siteDescription;
    private string $timezone;

    private Syndication $syndication;

    public function __construct(
        private Database $db,
        private Auth $auth,
        private array $config,
        private Builder $builder,
    ) {
        $this->siteUrl         = $db->getSetting('site_url', '');
        $this->siteTitle       = $db->getSetting('site_title', 'My CMS');
        $this->siteDescription = $db->getSetting('site_description', '');
        $this->timezone        = $db->getSetting('timezone', '');

        $this->syndication = new Syndication($db, $config);
    }

    /**
     * Dispatch one parsed XML-RPC call. Handlers echo their own response.
     *
     * @param array<mixed> $params
     * @throws XmlRpcFault on any error the caller should see as a <fault>
     */
    public function dispatch(string $method, array $params): void
    {
        switch ($method) {

            // ── blogger.getUsersBlogs(appkey, username, password) ─────────────────────
            case 'blogger.getUsersBlogs':
                $this->xmlrpc_auth($params, 1, 2);
                echo XmlRpc::encodeResponse([[
                    'blogid'   => '1',
                    'blogName' => $this->siteTitle,
                    'url'      => $this->siteUrl,
                ]]);
                break;

            // ── metaWeblog.getRecentPosts(blogid, username, password, numberOfPosts) ──
            // numberOfPosts=0 means "return all" (MarsEdit "Download all posts" mode).
            case 'metaWeblog.getRecentPosts':
                $this->xmlrpc_auth($params, 1, 2);
                $numberRaw = isset($params[3]) ? (int) $params[3] : 0;
                $limit     = $numberRaw > 0 ? $numberRaw : PHP_INT_MAX;
                $all       = Post::findAll($this->db, 'published');
                $posts     = array_slice($all, 0, $limit);
                $structs   = array_map(fn($p) => $this->postToStruct($p, $this->siteUrl, $this->timezone), $posts);
                $this->debug("  number=$numberRaw → " . count($posts) . " posts returned");
                echo XmlRpc::encodeResponse($structs);
                break;

            // ── metaWeblog.getPost(postid, username, password) ────────────────────────
            case 'metaWeblog.getPost':
                $this->xmlrpc_auth($params, 1, 2);
                $post = Post::findById($this->db, (int) ($params[0] ?? 0));
                if ($post === null) {
                    $this->fault(404, 'Post not found.');
                }
                echo XmlRpc::encodeResponse($this->postToStruct($post, $this->siteUrl, $this->timezone));
                break;

            // ── metaWeblog.newPost(blogid, username, password, struct, publish) ───────
            case 'metaWeblog.newPost':
                $this->xmlrpc_auth($params, 1, 2);
                $struct  = (array) ($params[3] ?? []);
                $publish = (bool) ($params[4] ?? false);

                $post = new Post($this->db);
                $this->applyStruct($post, $struct, $publish, $this->timezone);

                if ($post->title === '' && !$post->isNote()) {
                    $this->fault(400, 'Title is required.');
                }

                $post->save();
                $this->xmlrpc_finalize_aside_slug($post);
                $this->xmlrpc_apply_link_context($post, $struct);
                $this->xmlrpc_save_terms($post, $struct);
                $this->rebuildPost($post);
                $this->syndicatePost($post);

                echo XmlRpc::encodeResponse((string) $post->id);
                break;

            // ── metaWeblog.editPost(postid, username, password, struct, publish) ──────
            case 'metaWeblog.editPost':
                $this->xmlrpc_auth($params, 1, 2);
                $post = Post::findById($this->db, (int) ($params[0] ?? 0));
                if ($post === null) {
                    $this->fault(404, 'Post not found.');
                }
                $struct       = (array) ($params[3] ?? []);
                $publish      = (bool) ($params[4] ?? false);
                $wasPublished = $post->status === 'published';
                $before       = $this->xmlrpc_snapshot($post, $wasPublished);

                $this->applyStruct($post, $struct, $publish, $this->timezone);

                if ($post->title === '' && !$post->isNote()) {
                    $this->fault(400, 'Title is required.');
                }

                $post->save();
                $this->xmlrpc_finalize_aside_slug($post);
                $this->xmlrpc_apply_link_context($post, $struct);
                $this->xmlrpc_save_terms($post, $struct);
                $this->rebuildPost($post, $wasPublished, $before);
                $this->syndicatePost($post);
                $this->resyndicatePost($post, $wasPublished);

                echo XmlRpc::encodeResponse(true);
                break;

            // ── metaWeblog.deletePost(appkey, postid, username, password, publish) ────
            case 'metaWeblog.deletePost':
            case 'blogger.deletePost':
                $this->xmlrpc_auth($params, 2, 3);
                $post = Post::findById($this->db, (int) ($params[1] ?? 0));
                if ($post === null) {
                    $this->fault(404, 'Post not found.');
                }
                $wasPublished = $post->status === 'published';
                $prevNeighbor = $wasPublished ? Post::findPrev($this->db, $post) : null;
                $nextNeighbor = $wasPublished ? Post::findNext($this->db, $post) : null;
                // Before the row goes: the ids of the syndicated copies live on it.
                $this->syndication->remove($post);
                $post->delete();
                // buildPost() removal path also rebuilds taxonomy archives for $post->categories.
                $post->status = 'draft';
                $this->builder->buildPost($post);
                if ($wasPublished) {
                    if ($prevNeighbor) $this->builder->buildPost($prevNeighbor);
                    if ($nextNeighbor) $this->builder->buildPost($nextNeighbor);
                    $this->builder->rebuildSharedResources();
                }
                echo XmlRpc::encodeResponse(true);
                break;

            // ── metaWeblog.getCategories(blogid, username, password) ─────────────────
            case 'metaWeblog.getCategories':
                $this->xmlrpc_auth($params, 1, 2);
                $cats = $this->db->select("SELECT * FROM categories ORDER BY name");
                echo XmlRpc::encodeResponse(array_map(fn($c) => [
                    'categoryId'   => (string) $c['id'],
                    'categoryName' => $c['name'],
                    'description'  => $c['description'],
                    'htmlUrl'      => rtrim($this->siteUrl, '/') . '/category/' . rawurlencode($c['slug']) . '/',
                    'rssUrl'       => '',
                ], $cats));
                break;

            // ── metaWeblog.newMediaObject(blogid, username, password, struct) ─────────
            case 'metaWeblog.newMediaObject':
                $this->xmlrpc_auth($params, 1, 2);
                $struct       = (array) ($params[3] ?? []);
                $originalName = trim((string) ($struct['name'] ?? ''));
                $mimeType     = trim((string) ($struct['type'] ?? ''));
                $bits         = $struct['bits'] ?? '';

                if ($originalName === '' || !is_string($bits) || $bits === '') {
                    $this->fault(400, 'Invalid media object: name and bits are required.');
                }

                echo XmlRpc::encodeResponse($this->xmlrpc_media_struct(
                    $this->xmlrpc_save_media($originalName, $mimeType, $bits),
                    $mimeType
                ));
                break;

            // ════════════════════════════════════════════════════════════════════════
            // Movable Type API stubs
            // MarsEdit calls these during blog discovery regardless of API mode.
            // ════════════════════════════════════════════════════════════════════════

            // ── mt.supportedMethods() ────────────────────────────────────────────────
            case 'mt.supportedMethods':
                $this->xmlrpc_auth($params, 1, 2);
                echo XmlRpc::encodeResponse([
                    'blogger.getUsersBlogs',
                    'metaWeblog.getRecentPosts', 'metaWeblog.getPost',
                    'metaWeblog.newPost', 'metaWeblog.editPost', 'metaWeblog.deletePost',
                    'metaWeblog.getCategories', 'metaWeblog.newMediaObject',
                    'mt.supportedMethods', 'mt.supportedTextFilters',
                    'mt.getCategoryList', 'mt.getPostCategories', 'mt.setPostCategories',
                    'wp.getUsersBlogs', 'wp.getUsers', 'wp.getOptions', 'wp.getAuthors',
                    'wp.getPostFormats', 'wp.getTaxonomies', 'wp.getTerms',
                    'wp.getTags', 'wp.newCategory', 'wp.deleteCategory',
                    'wp.getPosts', 'wp.getPost', 'wp.newPost', 'wp.editPost', 'wp.deletePost',
                    'wp.getPages', 'wp.getPageList', 'wp.getPageStatusList', 'wp.getPage', 'wp.newPage', 'wp.editPage', 'wp.deletePage',
                    'wp.getMediaLibrary', 'wp.uploadFile',
                ]);
                break;

            // ── mt.supportedTextFilters(blogid, username, password) ──────────────────
            case 'mt.supportedTextFilters':
                $this->xmlrpc_auth($params, 1, 2);
                echo XmlRpc::encodeResponse([]);
                break;

            // ── mt.getCategoryList(blogid, username, password) ────────────────────────
            case 'mt.getCategoryList':
                $this->xmlrpc_auth($params, 1, 2);
                $cats = $this->db->select("SELECT id, name FROM categories ORDER BY name");
                echo XmlRpc::encodeResponse(array_map(fn($c) => [
                    'categoryId'   => (string) $c['id'],
                    'categoryName' => $c['name'],
                ], $cats));
                break;

            // ── mt.getPostCategories(postid, username, password) ─────────────────────
            case 'mt.getPostCategories':
                $this->xmlrpc_auth($params, 1, 2);
                $post = Post::findById($this->db, (int) ($params[0] ?? 0));
                if ($post === null) {
                    $this->fault(404, 'Post not found.');
                }
                echo XmlRpc::encodeResponse(array_map(fn($c) => [
                    'categoryId'   => (string) $c['id'],
                    'categoryName' => $c['name'],
                    'isPrimary'    => true,
                ], $post->categories));
                break;

            // ── mt.setPostCategories(postid, username, password, categories) ──────────
            case 'mt.setPostCategories':
                $this->xmlrpc_auth($params, 1, 2);
                $post = Post::findById($this->db, (int) ($params[0] ?? 0));
                if ($post === null) {
                    $this->fault(404, 'Post not found.');
                }
                $catStructs  = (array) ($params[3] ?? []);
                $categoryIds = [];
                foreach ($catStructs as $cs) {
                    $cid = (int) ($cs['categoryId'] ?? 0);
                    if ($cid > 0) {
                        $categoryIds[] = $cid;
                    }
                }
                $post->saveTerms($categoryIds, array_column($post->tags, 'id'));
                if ($post->status === 'published') {
                    $this->builder->buildPost($post);
                }
                echo XmlRpc::encodeResponse(true);
                break;

            // ════════════════════════════════════════════════════════════════════════
            // WordPress XML-RPC API
            // ════════════════════════════════════════════════════════════════════════

            // ── wp.getUsersBlogs(appkey, username, password) ──────────────────────────
            case 'wp.getUsersBlogs':
                $this->xmlrpc_auth($params, 1, 2);
                echo XmlRpc::encodeResponse([[
                    'blogid'   => '1',
                    'blogName' => $this->siteTitle,
                    'url'      => $this->siteUrl,
                    'xmlrpc'   => rtrim($this->siteUrl, '/') . '/admin/xmlrpc.php',
                    'isAdmin'  => true,
                ]]);
                break;

            // ── wp.getOptions(blogid, username, password[, options]) ──────────────────
            case 'wp.getOptions':
                $this->xmlrpc_auth($params, 1, 2);
                $opt = fn(string $desc, bool $ro, string $val): array =>
                    ['desc' => $desc, 'readonly' => $ro, 'value' => $val];
                echo XmlRpc::encodeResponse([
                    'software_name'    => $opt('Software Name',    true,  'WordPress'),
                    'software_version' => $opt('Software Version', true,  '6.4'),
                    'blog_url'         => $opt('Blog URL',         true,  $this->siteUrl),
                    'home_url'         => $opt('Home URL',         true,  $this->siteUrl),
                    'blog_title'       => $opt('Blog Title',       false, $this->siteTitle),
                    'blog_tagline'     => $opt('Tagline',          false, $this->siteDescription),
                    'time_zone'        => $opt('Time Zone',        true,  $this->timezone),
                    'date_format'      => $opt('Date Format',      true,  'Y-m-d'),
                    'time_format'      => $opt('Time Format',      true,  'H:i:s'),
                    'upload_path'      => $opt('Upload Path',      true,  '/media/'),
                    'thumbnail_size_w' => $opt('Thumbnail Width',  true,  '150'),
                    'thumbnail_size_h' => $opt('Thumbnail Height', true,  '150'),
                ]);
                break;

            // ── wp.getUsers(blogid, username, password[, filter]) ────────────────────
            case 'wp.getUsers':
                $this->xmlrpc_auth($params, 1, 2);
                $cfgUser = $this->config['admin']['username'] ?? '';
                $this->debug("  → returning user '$cfgUser'");
                echo XmlRpc::encodeResponse([[
                    'user_id'          => '1',
                    'user_login'       => $cfgUser,
                    'display_name'     => $cfgUser,
                    'user_registered'  => new \CMS\DateTimeValue(XmlRpc::isoDate(null)),
                    'bio'              => '',
                    'email'            => '',
                    'nickname'         => $cfgUser,
                    'firstname'        => '',
                    'lastname'         => '',
                    'url'              => $this->siteUrl,
                    'roles'            => ['administrator'],
                ]]);
                break;

            // ── wp.getAuthors(blogid, username, password) ─────────────────────────────
            case 'wp.getAuthors':
                $this->xmlrpc_auth($params, 1, 2);
                echo XmlRpc::encodeResponse([[
                    'user_id'      => '1',
                    'user_login'   => $this->config['admin']['username'] ?? '',
                    'display_name' => $this->config['admin']['username'] ?? '',
                    'user_email'   => '',
                    'meta_value'   => '',
                ]]);
                break;

            // ── wp.getPostFormats(blogid, username, password[, filter]) ───────────────
            case 'wp.getPostFormats':
                $this->xmlrpc_auth($params, 1, 2);
                echo XmlRpc::encodeResponse(self::XMLRPC_POST_FORMATS);
                break;

            // ── wp.getTaxonomies(blogid, username, password) ──────────────────────────
            case 'wp.getTaxonomies':
                $this->xmlrpc_auth($params, 1, 2);
                echo XmlRpc::encodeResponse([
                    [
                        'name'         => 'category',
                        'label'        => 'Categories',
                        'hierarchical' => true,
                        'public'       => true,
                        'show_ui'      => true,
                        'cap'          => ['manage_terms' => 'manage_categories', 'edit_terms' => 'manage_categories', 'delete_terms' => 'manage_categories', 'assign_terms' => 'edit_posts'],
                        '_builtin'     => true,
                        'labels'       => ['name' => 'Categories', 'singular_name' => 'Category'],
                    ],
                    [
                        'name'         => 'post_tag',
                        'label'        => 'Tags',
                        'hierarchical' => false,
                        'public'       => true,
                        'show_ui'      => true,
                        'cap'          => ['manage_terms' => 'manage_post_tags', 'edit_terms' => 'manage_post_tags', 'delete_terms' => 'manage_post_tags', 'assign_terms' => 'edit_posts'],
                        '_builtin'     => true,
                        'labels'       => ['name' => 'Tags', 'singular_name' => 'Tag'],
                    ],
                ]);
                break;

            // ── wp.getTerms(blogid, username, password, taxonomy[, filter]) ───────────
            case 'wp.getTerms':
                $this->xmlrpc_auth($params, 1, 2);
                $taxonomy = strtolower(trim((string) ($params[3] ?? '')));
                if ($taxonomy === 'category') {
                    $rows = $this->db->select(
                        "SELECT c.id, c.name, c.slug, c.description,
                                COUNT(pc.post_id) AS count
                           FROM categories c
                      LEFT JOIN post_categories pc ON pc.category_id = c.id
                          GROUP BY c.id ORDER BY c.name"
                    );
                    echo XmlRpc::encodeResponse(array_map(fn($c) => [
                        'term_id'     => (string) $c['id'],
                        'name'        => $c['name'],
                        'slug'        => $c['slug'],
                        'term_group'  => '0',
                        'term_taxonomy_id' => (string) $c['id'],
                        'taxonomy'    => 'category',
                        'description' => $c['description'],
                        'parent'      => '0',
                        'count'       => (int) $c['count'],
                    ], $rows));
                } elseif ($taxonomy === 'post_tag') {
                    $rows = $this->db->select(
                        "SELECT t.id, t.name, t.slug,
                                COUNT(pt.post_id) AS count
                           FROM tags t
                      LEFT JOIN post_tags pt ON pt.tag_id = t.id
                          GROUP BY t.id ORDER BY t.name"
                    );
                    echo XmlRpc::encodeResponse(array_map(fn($t) => [
                        'term_id'     => (string) $t['id'],
                        'name'        => $t['name'],
                        'slug'        => $t['slug'],
                        'term_group'  => '0',
                        'term_taxonomy_id' => (string) $t['id'],
                        'taxonomy'    => 'post_tag',
                        'description' => '',
                        'parent'      => '0',
                        'count'       => (int) $t['count'],
                    ], $rows));
                } else {
                    echo XmlRpc::encodeResponse([]);
                }
                break;

            // ── wp.getTags(blogid, username, password) ────────────────────────────────
            case 'wp.getTags':
                $this->xmlrpc_auth($params, 1, 2);
                $rows = $this->db->select(
                    "SELECT t.id, t.name, t.slug,
                            COUNT(pt.post_id) AS count
                       FROM tags t
                  LEFT JOIN post_tags pt ON pt.tag_id = t.id
                      GROUP BY t.id ORDER BY t.name"
                );
                echo XmlRpc::encodeResponse(array_map(fn($t) => [
                    'tag_id'   => (string) $t['id'],
                    'name'     => $t['name'],
                    'slug'     => $t['slug'],
                    'tag_description' => '',
                    'count'    => (int) $t['count'],
                    'html_url' => rtrim($this->siteUrl, '/') . '/tag/' . rawurlencode($t['slug']) . '/',
                    'rss_url'  => '',
                ], $rows));
                break;

            // ── wp.newCategory(blogid, username, password, category) ──────────────────
            case 'wp.newCategory':
                $this->xmlrpc_auth($params, 1, 2);
                $cat     = (array) ($params[3] ?? []);
                $name    = trim((string) ($cat['name'] ?? ''));
                $slug    = trim((string) ($cat['slug'] ?? ''));
                $desc    = trim((string) ($cat['description'] ?? ''));
                if ($name === '') {
                    $this->fault(400, 'Category name is required.');
                }
                if ($slug === '') {
                    $slug = Helpers::slugify($name);
                }
                $existing = $this->db->selectOne("SELECT id FROM categories WHERE slug = :slug", [':slug' => $slug]);
                if ($existing) {
                    echo XmlRpc::encodeResponse((int) $existing['id']);
                } else {
                    $id = $this->db->insert('categories', ['name' => $name, 'slug' => $slug, 'description' => $desc]);
                    echo XmlRpc::encodeResponse($id);
                }
                break;

            // ── wp.deleteCategory(blogid, username, password, category_id) ────────────
            case 'wp.deleteCategory':
                $this->xmlrpc_auth($params, 1, 2);
                $catId = (int) ($params[3] ?? 0);
                if ($catId <= 0) {
                    $this->fault(400, 'Invalid category ID.');
                }
                $this->db->delete('categories', 'id = :id', ['id' => $catId]);
                $this->builder->buildAllTaxonomyArchives();
                echo XmlRpc::encodeResponse(true);
                break;

            // ── wp.getPosts(blogid, username, password[, filter]) ─────────────────────
            // MarsEdit uses post_type='page' filter to list pages via this method.
            // number=0 (or absent) means "return all" — used by "Download all posts".
            case 'wp.getPosts':
                $this->xmlrpc_auth($params, 1, 2);
                $filter      = (array) ($params[3] ?? []);
                $postType    = strtolower(trim((string) ($filter['post_type'] ?? 'post')));
                $numberRaw   = isset($filter['number']) ? (int) $filter['number'] : 0;
                $limit       = $numberRaw > 0 ? $numberRaw : PHP_INT_MAX;
                $offset      = (int) ($filter['offset'] ?? 0);

                $this->debug("  post_type=$postType post_status=" . ($filter['post_status'] ?? '(none)') . " number=$numberRaw offset=$offset limit=" . ($limit === PHP_INT_MAX ? 'ALL' : $limit));

                if ($postType === 'page') {
                    $all    = Page::findAll($this->db);
                    $sliced = array_slice($all, $offset, $limit);
                    $this->debug("  → " . count($sliced) . " pages (of " . count($all) . " total)");
                    echo XmlRpc::encodeResponse(array_map(fn($pg) => $this->wpPageToStruct($pg, $this->siteUrl), $sliced));
                } else {
                    $wpStat = strtolower(trim((string) ($filter['post_status'] ?? 'any')));
                    $status = ($wpStat === 'any' || $wpStat === '') ? null : $this->cmsStatusFromWp($wpStat);
                    $all    = Post::findAll($this->db, $status);
                    $sliced = array_slice($all, $offset, $limit);
                    $this->debug("  → " . count($sliced) . " posts (of " . count($all) . " total, db_status_filter=" . ($status ?? 'all') . ")");
                    $structs = array_map(fn($p) => $this->wpPostToStruct($p, $this->siteUrl, $this->timezone), $sliced);
                    echo XmlRpc::encodeResponse($structs);
                }
                break;

            // ── wp.getPost(blogid, username, password, post_id) ───────────────────────
            // IDs at or above PAGE_ID_OFFSET are pages (offset encoded in wpPageToStruct).
            case 'wp.getPost':
                $this->xmlrpc_auth($params, 1, 2);
                $postId = (int) ($params[3] ?? 0);
                $this->debug("  post_id=$postId" . ($postId >= self::PAGE_ID_OFFSET ? " (page, real_id=" . ($postId - self::PAGE_ID_OFFSET) . ")" : ""));
                if ($postId >= self::PAGE_ID_OFFSET) {
                    $page = Page::findById($this->db, $postId - self::PAGE_ID_OFFSET);
                    if ($page === null) {
                        $this->debug("  → 404 page not found");
                        $this->fault(404, 'Page not found.');
                    }
                    $this->debug("  → page '{$page->title}' status={$page->status}");
                    echo XmlRpc::encodeResponse($this->wpPageToStruct($page, $this->siteUrl));
                    break;
                }
                $post = Post::findById($this->db, $postId);
                if ($post === null) {
                    $this->debug("  → 404 post not found");
                    $this->fault(404, 'Post not found.');
                }
                $this->debug("  → post '{$post->title}' status={$post->status} pub=" . ($post->published_at ?? 'null'));
                echo XmlRpc::encodeResponse($this->wpPostToStruct($post, $this->siteUrl, $this->timezone));
                break;

            // ── wp.newPost(blogid, username, password, content) ───────────────────────
            // MarsEdit passes post_type='page' when creating a page from the Pages section.
            case 'wp.newPost':
                $this->xmlrpc_auth($params, 1, 2);
                $struct   = (array) ($params[3] ?? []);
                $postType = strtolower(trim((string) ($struct['post_type'] ?? 'post')));

                if ($postType === 'page') {
                    $page = new Page($this->db);
                    $this->applyWpPageStruct($page, $struct);
                    if ($page->title === '') {
                        $this->fault(400, 'Title is required.');
                    }
                    $page->save();
                    $this->rebuildPage($page, false);
                    echo XmlRpc::encodeResponse((string) ($page->id + self::PAGE_ID_OFFSET));
                } else {
                    $post = new Post($this->db);
                    $this->applyWpPostStruct($post, $struct, $this->timezone);
                    if ($post->title === '' && !$post->isNote()) {
                        $this->fault(400, 'Title is required.');
                    }
                    $post->save();
                    $this->xmlrpc_finalize_aside_slug($post);
                    $this->xmlrpc_apply_link_context($post, $struct);
                    $this->xmlrpc_save_terms($post, $struct);
                    $this->rebuildPost($post);
                    $this->syndicatePost($post);
                    echo XmlRpc::encodeResponse((string) $post->id);
                }
                break;

            // ── wp.editPost(blogid, username, password, post_id, content) ────────────
            // IDs at or above PAGE_ID_OFFSET are pages (offset encoded in wpPageToStruct).
            case 'wp.editPost':
                $this->xmlrpc_auth($params, 1, 2);
                $postId = (int) ($params[3] ?? 0);
                $struct = (array) ($params[4] ?? []);

                if ($postId >= self::PAGE_ID_OFFSET) {
                    $page = Page::findById($this->db, $postId - self::PAGE_ID_OFFSET);
                    if ($page === null) {
                        $this->fault(404, 'Page not found.');
                    }
                    $wasPublished = $page->status === 'published';
                    $this->applyWpPageStruct($page, $struct);
                    if ($page->title === '') {
                        $this->fault(400, 'Title is required.');
                    }
                    $page->save();
                    $this->rebuildPage($page, $wasPublished);
                    echo XmlRpc::encodeResponse(true);
                    break;
                }

                $post = Post::findById($this->db, $postId);
                if ($post === null) {
                    $this->fault(404, 'Post not found.');
                }
                $wasPublished = $post->status === 'published';
                $before       = $this->xmlrpc_snapshot($post, $wasPublished);
                $this->applyWpPostStruct($post, $struct, $this->timezone);
                if ($post->title === '' && !$post->isNote()) {
                    $this->fault(400, 'Title is required.');
                }
                $post->save();
                $this->xmlrpc_finalize_aside_slug($post);
                $this->xmlrpc_apply_link_context($post, $struct);
                $this->xmlrpc_save_terms($post, $struct);
                $this->rebuildPost($post, $wasPublished, $before);
                $this->syndicatePost($post);
                $this->resyndicatePost($post, $wasPublished);
                echo XmlRpc::encodeResponse(true);
                break;

            // ── wp.deletePost(blogid, username, password, post_id) ────────────────────
            // IDs at or above PAGE_ID_OFFSET are pages (offset encoded in wpPageToStruct).
            case 'wp.deletePost':
                $this->xmlrpc_auth($params, 1, 2);
                $postId = (int) ($params[3] ?? 0);

                if ($postId >= self::PAGE_ID_OFFSET) {
                    $page = Page::findById($this->db, $postId - self::PAGE_ID_OFFSET);
                    if ($page === null) {
                        $this->fault(404, 'Page not found.');
                    }
                    $wasPublished = $page->status === 'published';
                    $page->delete();
                    $page->status = 'draft';
                    $this->builder->buildPage($page);
                    if ($wasPublished) {
                        $this->builder->buildIndex();
                    }
                    echo XmlRpc::encodeResponse(true);
                    break;
                }

                $post = Post::findById($this->db, $postId);
                if ($post === null) {
                    $this->fault(404, 'Post not found.');
                }
                $wasPublished = $post->status === 'published';
                $prevNeighbor = $wasPublished ? Post::findPrev($this->db, $post) : null;
                $nextNeighbor = $wasPublished ? Post::findNext($this->db, $post) : null;
                // Before the row goes: the ids of the syndicated copies live on it.
                $this->syndication->remove($post);
                $post->delete();
                // buildPost() removal path also rebuilds taxonomy archives for $post->categories.
                $post->status = 'draft';
                $this->builder->buildPost($post);
                if ($wasPublished) {
                    if ($prevNeighbor) $this->builder->buildPost($prevNeighbor);
                    if ($nextNeighbor) $this->builder->buildPost($nextNeighbor);
                    $this->builder->rebuildSharedResources();
                }
                echo XmlRpc::encodeResponse(true);
                break;

            // ── wp.getPages(blogid, username, password[, num_pages]) ──────────────────
            case 'wp.getPages':
                $this->xmlrpc_auth($params, 1, 2);
                $limit = isset($params[3]) ? max(1, (int) $params[3]) : PHP_INT_MAX;
                $all   = Page::findAll($this->db);        // all statuses — MarsEdit needs drafts
                $pages = array_slice($all, 0, $limit);
                echo XmlRpc::encodeResponse(array_map(fn($pg) => $this->wpPageToStruct($pg, $this->siteUrl), $pages));
                break;

            // ── wp.getPageList(blogid, username, password) ────────────────────────────
            // MarsEdit calls this (not wp.getPages) to populate its Pages sidebar.
            // Returns a lightweight index per the WP spec: page_id and page_parent_id
            // must be integers (not strings) or MarsEdit may silently drop the list.
            case 'wp.getPageList':
                $this->xmlrpc_auth($params, 1, 2);
                $all = Page::findAll($this->db);
                $list = array_map(fn(Page $pg): array => [
                    'page_id'          => (int) $pg->id,
                    'page_title'       => $pg->title,
                    'page_parent_id'   => 0,
                    'dateCreated'      => new \CMS\DateTimeValue(XmlRpc::isoDate($pg->created_at)),
                    'date_created_gmt' => new \CMS\DateTimeValue(XmlRpc::isoDate($pg->created_at)),
                ], $all);
                echo XmlRpc::encodeResponse($list);
                break;

            // ── wp.getPageStatusList(blogid, username, password) ─────────────────────
            case 'wp.getPageStatusList':
                $this->xmlrpc_auth($params, 1, 2);
                echo XmlRpc::encodeResponse(['draft' => 'Draft', 'publish' => 'Published']);
                break;

            // ── wp.getPage(blogid, username, password, page_id) ───────────────────────
            case 'wp.getPage':
                $this->xmlrpc_auth($params, 1, 2);
                $page = Page::findById($this->db, (int) ($params[3] ?? 0));
                if ($page === null) {
                    $this->fault(404, 'Page not found.');
                }
                echo XmlRpc::encodeResponse($this->wpPageToStruct($page, $this->siteUrl));
                break;

            // ── wp.newPage(blogid, username, password, content[, publish]) ────────────
            case 'wp.newPage':
                $this->xmlrpc_auth($params, 1, 2);
                $struct  = (array) ($params[3] ?? []);
                $publish = (bool) ($params[4] ?? true);

                $page = new Page($this->db);
                $this->applyWpPageStruct($page, $struct);

                // Honour explicit $publish=false even if struct says 'publish'.
                if (!$publish) {
                    $page->status = 'draft';
                }

                if ($page->title === '') {
                    $this->fault(400, 'Title is required.');
                }

                $page->save();
                $this->rebuildPage($page, false);

                echo XmlRpc::encodeResponse((string) $page->id);
                break;

            // ── wp.editPage(blogid, username, password, page_id, content[, publish]) ──
            case 'wp.editPage':
                $this->xmlrpc_auth($params, 1, 2);
                $page = Page::findById($this->db, (int) ($params[3] ?? 0));
                if ($page === null) {
                    $this->fault(404, 'Page not found.');
                }
                $wasPublished = $page->status === 'published';
                $struct       = (array) ($params[4] ?? []);
                $publish      = isset($params[5]) ? (bool) $params[5] : null;

                $this->applyWpPageStruct($page, $struct);

                // Explicit $publish param overrides struct's post_status.
                if ($publish === false) {
                    $page->status = 'draft';
                } elseif ($publish === true) {
                    $page->status = 'published';
                }

                if ($page->title === '') {
                    $this->fault(400, 'Title is required.');
                }

                $page->save();
                $this->rebuildPage($page, $wasPublished);

                echo XmlRpc::encodeResponse(true);
                break;

            // ── wp.deletePage(blogid, username, password, page_id) ────────────────────
            case 'wp.deletePage':
                $this->xmlrpc_auth($params, 1, 2);
                $page = Page::findById($this->db, (int) ($params[3] ?? 0));
                if ($page === null) {
                    $this->fault(404, 'Page not found.');
                }
                $wasPublished = $page->status === 'published';
                $page->delete();
                $page->status = 'draft';
                $this->builder->buildPage($page);     // removes the static file
                if ($wasPublished) {
                    $this->builder->buildIndex();     // refresh nav on all pages
                }
                echo XmlRpc::encodeResponse(true);
                break;

            // ── wp.getMediaLibrary(blogid, username, password[, filter]) ─────────────
            case 'wp.getMediaLibrary':
                $this->xmlrpc_auth($params, 1, 2);
                $filter    = (array) ($params[3] ?? []);
                $numberRaw = isset($filter['number']) ? (int) $filter['number'] : 0;
                $limit     = $numberRaw > 0 ? $numberRaw : PHP_INT_MAX;
                $offset    = max(0, (int) ($filter['offset'] ?? 0));
                $rows    = $this->db->select(
                    "SELECT * FROM media ORDER BY uploaded_at DESC LIMIT :lim OFFSET :off",
                    ['lim' => $limit, 'off' => $offset]
                );
                $items = [];
                foreach ($rows as $row) {
                    $url      = rtrim($this->siteUrl, '/') . '/media/' . $row['filename'];
                    $isImage  = str_starts_with((string) $row['mime_type'], 'image/');
                    $items[] = [
                        'attachment_id'   => (string) ($row['id'] + self::MEDIA_ID_OFFSET),
                        'date_created_gmt' => new \CMS\DateTimeValue(XmlRpc::isoDate($row['uploaded_at'])),
                        'parent'          => 0,
                        'link'            => $url,
                        'title'           => $row['original_name'],
                        'caption'         => '',
                        'description'     => '',
                        'metadata'        => [
                            'width'  => 0,
                            'height' => 0,
                            'file'   => $row['filename'],
                            'sizes'  => [],
                        ],
                        'thumbnail'       => $isImage ? $url : '',
                        'mime_type'       => $row['mime_type'],
                    ];
                }
                echo XmlRpc::encodeResponse($items);
                break;

            // ── wp.uploadFile(blogid, username, password, data) ───────────────────────
            case 'wp.uploadFile':
                $this->xmlrpc_auth($params, 1, 2);
                $data         = (array) ($params[3] ?? []);
                $originalName = trim((string) ($data['name'] ?? ''));
                $mimeType     = trim((string) ($data['type'] ?? ''));
                $bits         = $data['bits'] ?? '';

                if ($originalName === '' || !is_string($bits) || $bits === '') {
                    $this->fault(400, 'Invalid upload: name and bits are required.');
                }

                echo XmlRpc::encodeResponse($this->xmlrpc_media_struct(
                    $this->xmlrpc_save_media($originalName, $mimeType, $bits),
                    $mimeType
                ));
                break;

            // ── Unknown method ────────────────────────────────────────────────────────
            default:
                $this->fault(404, 'Unknown method: ' . $method);
        }    }

    /** Raise a fault; the front controller renders it. */
    private function fault(int $code, string $msg): never
    {
        throw new XmlRpcFault($msg, $code);
    }

    /**
     * Debug logging, opt-in: `touch data/xmlrpc.log` to enable, delete to stop.
     */
    private function debug(string $msg): void
    {
        $logPath = ($this->config['paths']['data'] ?? '') . '/xmlrpc.log';
        if (file_exists($logPath)) {
            file_put_contents(
                $logPath,
                date('H:i:s.') . substr((string) microtime(), 2, 3) . ' ' . $msg . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────

    /**
     * Authenticate username + password from XML-RPC params.
     * Records the attempt in login_attempts under the 'xmlrpc' scope for rate-limit
     * purposes, kept separate from the admin login's counter.
     */
    private function xmlrpc_auth(array $params, int $userIdx, int $passIdx): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Scoped to 'xmlrpc' so a misconfigured MarsEdit account cannot lock the
        // owner out of the admin login from the same IP.
        if ($this->auth->isLockedOut($ip, \CMS\Auth::SCOPE_XMLRPC)) {
            $this->fault(429, 'Too many failed login attempts. Try again later.');
        }

        $username = (string) ($params[$userIdx] ?? '');
        $password = (string) ($params[$passIdx] ?? '');

        $cfgUser = $this->config['admin']['username'] ?? '';
        $cfgHash = $this->config['admin']['password_hash'] ?? '';

        $ok = hash_equals($cfgUser, $username) && password_verify($password, $cfgHash);

        if (!$ok) {
            $this->db->insert('login_attempts', [
                'ip'           => $ip,
                'scope'        => \CMS\Auth::SCOPE_XMLRPC,
                'success'      => 0,
                'attempted_at' => date('Y-m-d H:i:s'),
            ]);
            $this->fault(403, 'Forbidden: incorrect username or password.');
        }
    }

    /**
     * Build the struct array that MarsEdit expects for a single post.
     */
    private function postToStruct(Post $post, string $siteUrl, string $timezone = ''): array
    {
        $pubAt  = $post->published_at ?? $post->created_at;
        $url    = rtrim($siteUrl, '/') . '/' . Post::datePath($pubAt, $post->slug, $timezone) . '/';

        return [
            'postid'         => (string) $post->id,
            'userid'         => '1',
            'title'          => $post->title,
            'description'    => $post->content,
            'mt_excerpt'     => $post->excerpt ?? '',
            'wp_slug'        => $post->slug,
            'dateCreated'    => new \CMS\DateTimeValue(XmlRpc::isoDate($pubAt)),
            'link'           => $url,
            'permaLink'      => $url,
            'categories'     => array_column($post->categories, 'name'),
            'post_status'    => $post->status,
            'wp_post_format' => $this->xmlrpc_format_from_post($post),
            // Sent back as an absolute URL rather than an attachment id: the id
            // only exists for a picture that came through our media library, and
            // a featured image may have arrived from Micropub as a bare URL. A
            // client that echoes this struct back round-trips either way —
            // xmlrpc_apply_featured_image() takes both forms.
            'wp_post_thumbnail' => $this->xmlrpc_featured_url($post, $siteUrl),
        ];
    }

    /** A post's stored featured image as an absolute URL, or '' when it has none. */
    private function xmlrpc_featured_url(Post $post, string $siteUrl): string
    {
        $url = (string) $post->featured_image_url;
        if ($url === '') {
            return '';
        }
        return str_starts_with($url, '/') ? rtrim($siteUrl, '/') . $url : $url;
    }

    /**
     * WordPress post formats this endpoint speaks, as advertised by
     * wp.getPostFormats. WordPress's vocabulary is closed, so these are its names,
     * not ours: `photo` travels as `image` or `gallery` depending on how many
     * pictures it carries, and a post bookmarking something travels as `link`.
     */
    /** Post formats advertised to MarsEdit (wp.getPostFormats). */
    public const XMLRPC_POST_FORMATS = [
        'standard' => 'Standard',
        'aside'    => 'Aside',
        'image'    => 'Image',
        'gallery'  => 'Gallery',
        'link'     => 'Link',
    ];

    /**
     * Count the pictures in a post. Photos attached as u-photo rows (how Micropub
     * and the admin editor store them) and images written inline in the body (how
     * MarsEdit stores them, via metaWeblog.newMediaObject) both count, so a gallery
     * reads as a gallery whichever client built it.
     */
    private function xmlrpc_image_count(Post $post): int
    {
        return count($post->photos) + preg_match_all('/<img\b/i', $post->content);
    }

    /**
     * Translate a post to its WordPress post format name for output.
     *
     * `link` wins over everything: a post carrying a bookmark-of context is a link
     * post in WordPress's vocabulary regardless of what else it holds, and it is
     * the only format that round-trips a context. Reply, like and repost contexts
     * have no WordPress equivalent, so those posts travel as their bare kind.
     */
    private function xmlrpc_format_from_post(Post $post): string
    {
        foreach ($post->contexts as $context) {
            if (($context['kind'] ?? '') === 'bookmark-of') {
                return 'link';
            }
        }

        if ($post->post_kind === 'photo') {
            return $this->xmlrpc_image_count($post) > 1 ? 'gallery' : 'image';
        }

        return $post->post_kind;
    }

    /**
     * The first http(s) URL in a body, as a link post's bookmark target. WordPress
     * derives a link post's URL the same way — from the content rather than a field
     * of its own — because no MetaWeblog or WordPress struct carries one.
     *
     * Handles HTML anchors, Markdown links and autolinks, and finally a bare URL;
     * MarsEdit may send any of them depending on how its editor is configured. In
     * every pattern the URL is the last capture group.
     */
    private function xmlrpc_first_link_url(string $content): string
    {
        $patterns = [
            '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/i',          // <a href="…">
            '/\[[^\]]*\]\(\s*<?([^)\s>]+)>?/',                  // [text](…)
            '/<(https?:\/\/[^>\s]+)>/i',                        // <https://…>
            '/(?<![\w"\'(\[])(https?:\/\/[^\s<>"\')\]]+)/i',    // bare https://…
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $content, $m)) {
                continue;
            }
            $url    = html_entity_decode(trim((string) end($m)), ENT_QUOTES, 'UTF-8');
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Pick the post_kind from an incoming struct (MetaWeblog or WordPress).
     * Accepts the WordPress format names plus our internal `photo`, and treats
     * `status` as an alias for `aside` (both are WordPress's titleless note).
     *
     * Returns null when the struct carries no format key at all — the caller keeps
     * the post's existing kind rather than demoting it, so a client that edits a
     * post without touching its format can't silently reshape it. This mirrors
     * Micropub update, which likewise never re-derives the kind.
     */
    private function xmlrpc_kind_from_struct(array $struct): ?string
    {
        $raw = $struct['wp_post_format'] ?? $struct['post_format'] ?? null;
        if ($raw === null) {
            return null;
        }

        $format = strtolower(trim((string) $raw));

        // A link post has no kind of its own — the bookmark lives in a context, so
        // the post is shaped by its title like any other: titled → standard,
        // titleless → aside. (Every client that sends a format sends a title key
        // alongside it, even when empty.)
        if ($format === 'link') {
            $title = trim((string) ($struct['title'] ?? $struct['post_title'] ?? ''));
            return $title !== '' ? 'standard' : 'aside';
        }

        return match ($format) {
            'aside', 'status'           => 'aside',
            'image', 'photo', 'gallery' => 'photo',
            default                     => 'standard',
        };
    }

    /**
     * Apply the link format's bookmark-of context, after save() so the post has an
     * id. Only runs when the struct actually states a format: a client editing a
     * post without touching its format leaves every context alone.
     *
     * Switching a post away from `link` drops the bookmark-of context, which is
     * what picking a different format in the client means. Reply, like and repost
     * contexts are never touched — no WordPress format maps to them, so an edit
     * from a WordPress client must not be able to destroy them.
     */
    private function xmlrpc_apply_link_context(Post $post, array $struct): void
    {
        $raw = $struct['wp_post_format'] ?? $struct['post_format'] ?? null;
        if ($raw === null || $post->id === null) {
            return;
        }

        $others = array_values(array_filter(
            $post->contexts,
            fn(array $c) => ($c['kind'] ?? '') !== 'bookmark-of'
        ));

        if (strtolower(trim((string) $raw)) !== 'link') {
            if (count($others) !== count($post->contexts)) {
                $post->saveContexts($others);
            }
            return;
        }

        // A body with no link in it — a bookmark posted as a bare comment, say —
        // leaves any existing target in place rather than clearing it.
        $url = $this->xmlrpc_first_link_url($post->content);
        if ($url === '') {
            return;
        }

        $post->saveContexts(array_merge($others, [['kind' => 'bookmark-of', 'url' => $url]]));
    }

    /**
     * Apply the incoming struct's post format to $post, if it carries one.
     * A new post with no format stated is a standard post.
     */
    private function xmlrpc_apply_kind(Post $post, array $struct): void
    {
        $kind = $this->xmlrpc_kind_from_struct($struct);

        if ($kind !== null) {
            $post->post_kind = $kind;
        } elseif ($post->id === null) {
            $post->post_kind = 'standard';
        }
    }

    /**
     * Resolve a unique slug for $post from the raw string $raw.
     */
    private function xmlrpc_resolve_slug(Post $post, string $raw): string
    {
        return Post::resolveUniqueSlug($this->db, $raw, $post->id);
    }

    /**
     * After a post has been saved (id is known), give an aside with no words to slug
     * from — a photo-only note — the autoincrement id as its slug.
     * Idempotent: only writes when the slug is still empty.
     */
    private function xmlrpc_finalize_aside_slug(Post $post): void
    {
        if ($post->slug !== '' || $post->id === null) {
            return;
        }
        $post->slug = (string) $post->id;
        $post->save();
    }

    /**
     * Apply a MetaWeblog content struct onto a Post object.
     * Mutates $post in place; does not save.
     */
    private function applyStruct(Post $post, array $struct, bool $publish, string $timezone): void
    {
        // Post kind (WordPress post_format). 'aside' = titleless note.
        $this->xmlrpc_apply_kind($post, $struct);

        // Featured image (WordPress wp_post_thumbnail). Applied in the mapper
        // rather than beside each save() so newPost and editPost are covered by
        // construction — there are four post handlers and the ones that got
        // edited one at a time are exactly the ones that went wrong before.
        $this->xmlrpc_apply_featured_image($post, $struct);

        // Title
        if (isset($struct['title'])) {
            $post->title = trim((string) $struct['title']);
        }

        // Body
        if (isset($struct['description'])) {
            $post->content = (string) $struct['description'];
        }

        // Excerpt
        if (array_key_exists('mt_excerpt', $struct)) {
            $ex = trim((string) $struct['mt_excerpt']);
            $post->excerpt = $ex !== '' ? $ex : null;
        }

        // Slug — an aside with none yet derives one from its body; a photo-only note
        // yields nothing and defers to $this->xmlrpc_finalize_aside_slug() after save().
        // A standard post holding a digit-only slug is a converted legacy aside and
        // re-derives, since digit-only slugs belong to that older numbering.
        $rawSlug = trim((string) ($struct['wp_slug'] ?? ''));
        if ($rawSlug === '') {
            if ($post->isNote()) {
                if ($post->slug === '') {
                    $rawSlug = Post::slugFromContent($post->content);
                }
            } elseif ($post->slug === '' || ctype_digit($post->slug)) {
                $rawSlug = $post->title;
            }
        }
        if ($rawSlug !== '') {
            $post->slug = $this->xmlrpc_resolve_slug($post, $rawSlug);
        }

        // post_status field overrides $publish for draft detection.
        $postStatus = strtolower(trim((string) ($struct['post_status'] ?? '')));
        if ($postStatus === 'draft') {
            $publish = false;
        }

        // Date
        $pubAt = XmlRpc::parseStructDate($struct, 'dateCreated', 'date_created_gmt', $timezone);

        // Status
        if (!$publish) {
            $post->status = 'draft';
            // Preserve published_at if already set; update if caller supplied one.
            if ($pubAt !== null) {
                $post->published_at = $pubAt;
            }
        } else {
            $effectivePubAt = $pubAt ?? $post->published_at ?? date('Y-m-d H:i:s');
            $post->published_at = $effectivePubAt;
            $post->status = strtotime($effectivePubAt) > time() ? 'scheduled' : 'published';
        }
    }

    /**
     * Syndicate a newly-published post to Mastodon and/or Bluesky.
     * Only fires when status = 'published' and the post has not already been shared.
     *
     * **Call this after rebuildPost(), never before.** Mastodon fetches the
     * permalink to build its preview card exactly once, seconds after the
     * status is created, and never retries a fetch that failed; Bluesky needs
     * the OG image on disk to upload as the card thumbnail. A post MarsEdit has
     * just created has neither until the build runs, so syndicating first cost
     * the Mastodon card outright and left the Bluesky card pictureless. All
     * four call sites here had it backwards until 1.21.2.
     */
    private function syndicatePost(Post $post): void
    {
        if ($post->status !== 'published') {
            return;
        }

        $before = [$post->mastodon_url, $post->bluesky_url, $post->pixelfed_url];
        $this->syndication->publish($post);

        // The post page lists the copies it made, so a syndication that
        // recorded a URL leaves the page just built a version behind. Only
        // post.php renders these URLs; feeds and index need no second pass.
        if ([$post->mastodon_url, $post->bluesky_url, $post->pixelfed_url] !== $before) {
            $this->builder->buildPost($post);
        }
    }

    /**
     * Bring the syndicated copies into line with a post MarsEdit has just
     * changed, or take them down when the change pulled it off the public site.
     */
    private function resyndicatePost(Post $post, bool $wasPublished): void
    {
        if ($wasPublished && $post->status !== 'published') {
            $this->syndication->remove($post);
        } elseif ($post->status === 'published') {
            $this->syndication->update($post);
        }
    }

    /**
     * Save binary media data to content/media/ and register it in the DB.
     * Mirrors the filename pattern from Media.php: {stem}_{8hex}.{ext}
     * Returns ['url' => string] struct on success; calls $this->fault() on error.
     */
    private function xmlrpc_save_media(string $originalName, string $mimeType, string $bits): array
    {
        // The one allowlist, shared with Media::upload(). This used to be a
        // hand-copied duplicate labelled "mirrors Media.php", which is a
        // promise no compiler keeps.
        $allowed = Media::ALLOWED_MIME;

        // Every other upload path checks a size — Media::upload() against the
        // posted length, Media::ingestFromUrl() against what it downloaded.
        // This one checked nothing, so the only ceiling was nginx's
        // client_max_body_size and a base64 body could fill the disk.
        $maxBytes = (int) ($this->config['media']['max_bytes'] ?? Media::DEFAULT_MAX_BYTES);
        if (strlen($bits) > $maxBytes) {
            $this->fault(400, 'File exceeds the maximum upload size of '
                . round($maxBytes / 1_048_576) . ' MB.');
        }

        if (!isset($allowed[$mimeType])) {
            $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $mimeType = array_search($ext, $allowed, true) ?: '';
        }

        if (!isset($allowed[$mimeType])) {
            $this->fault(400, 'Unsupported file type.');
        }

        // Verify actual file contents match the declared MIME type.
        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bits);
        if ($detectedMime !== $mimeType) {
            $this->fault(400, 'File content does not match declared type.');
        }

        $ext      = $allowed[$mimeType];
        $stem     = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
        $stem     = preg_replace('/[^a-z0-9_-]+/', '-', $stem);
        $stem     = trim($stem, '-');
        $stem     = mb_substr($stem !== '' ? $stem : 'file', 0, 60);

        $mediaDir = $this->config['paths']['content'] . '/media';
        if (!is_dir($mediaDir)) {
            mkdir($mediaDir, 0775, true);
        }

        $filename = '';
        for ($i = 0; $i < 10; $i++) {
            $candidate = $stem . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!file_exists($mediaDir . '/' . $candidate)) {
                $filename = $candidate;
                break;
            }
        }

        if ($filename === '') {
            $this->fault(500, 'Could not generate a unique filename.');
        }

        if (file_put_contents($mediaDir . '/' . $filename, $bits) === false) {
            $this->fault(500, 'Failed to write media file.');
        }

        // The WebP companions every other upload path writes. Without them an
        // image posted from MarsEdit renders as a bare <img> forever, because
        // ImageTag::render() falls back when the companions are missing — the
        // whole responsive pipeline quietly skipped a whole upload route.
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            (new Media($this->db, $mediaDir, $maxBytes))->generateWebp($mediaDir . '/' . $filename);
        }

        $id = $this->db->insert('media', [
            'filename'      => $filename,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size'          => strlen($bits),
            'uploaded_at'   => date('Y-m-d H:i:s'),
        ]);

        return [
            'id'       => $id,
            'filename' => $filename,
            'url'      => rtrim($this->siteUrl, '/') . '/media/' . $filename,
        ];
    }

    /**
     * The upload response both media methods answer with.
     *
     * `id` and `attachment_id` are what a WordPress client needs before it can
     * name a featured image at all: wp_post_thumbnail is an attachment id, so a
     * response carrying only a URL leaves the client nothing to send back. The
     * id is offset the same way wp.getMediaLibrary reports it, so the two agree.
     *
     * @param array{id:int,filename:string,url:string} $saved
     * @return array<string,mixed>
     */
    private function xmlrpc_media_struct(array $saved, string $mimeType): array
    {
        return [
            'id'            => (string) ($saved['id'] + self::MEDIA_ID_OFFSET),
            'attachment_id' => (string) ($saved['id'] + self::MEDIA_ID_OFFSET),
            'file'          => $saved['filename'],
            'url'           => $saved['url'],
            'type'          => $mimeType,
        ];
    }

    /**
     * Apply a featured image from a post struct, if the client sent one.
     *
     * WordPress names it `wp_post_thumbnail` and its value is an attachment id;
     * `post_thumbnail` is what wp.getPost answers with, so accept it back too.
     * A bare URL is accepted as well — not every client that wants to set a lead
     * picture speaks in attachment ids, and the media endpoint hands out URLs.
     *
     * An **absent** key leaves the field alone. An edit struct that never
     * mentions the thumbnail must not clear one, the same rule
     * xmlrpc_kind_from_struct() follows for the post format. An empty string is
     * a client saying "none", and does clear it.
     *
     * Mutates $post; the caller saves.
     */
    private function xmlrpc_apply_featured_image(Post $post, array $struct): void
    {
        $raw = $struct['wp_post_thumbnail'] ?? $struct['post_thumbnail'] ?? null;
        if ($raw === null || is_array($raw)) {
            return;
        }

        $value = trim((string) $raw);
        if ($value === '' || $value === '0') {
            $post->featured_image_url = null;
            $post->featured_image_alt = '';
            return;
        }

        if (ctype_digit($value)) {
            // Offset first, then the bare row id — a client echoing back what
            // wp.getMediaLibrary gave it sends the offset form, and one that
            // read the id straight out of an upload response may not.
            $id  = (int) $value;
            $row = $this->db->selectOne(
                "SELECT filename, original_name FROM media WHERE id = :id",
                ['id' => $id >= self::MEDIA_ID_OFFSET ? $id - self::MEDIA_ID_OFFSET : $id]
            );
            if ($row === null) {
                return;
            }
            $post->featured_image_url = '/media/' . rawurlencode((string) $row['filename']);
            // No alt travels with a thumbnail id. The original filename is a
            // placeholder the author can fix in the admin, and better than the
            // empty string a screen reader gets otherwise.
            if ($post->featured_image_alt === '') {
                $post->featured_image_alt = (string) $row['original_name'];
            }
            return;
        }

        // A URL. Same allowlist as everywhere else — it reaches an href.
        $url = Post::normaliseImageUrl($value, $this->siteUrl);
        if ($url !== null) {
            $post->featured_image_url = $url;
        }
    }

    /**
     * Run the appropriate Builder calls after saving a post.
     * Mirrors the logic in admin/post-edit.php.
     *
     * $oldDir is the post's output directory as it stood before the save, from
     * Builder::postOutputDir(); pass it on an edit so a renamed or re-dated post
     * does not leave its previous URL serving the old HTML.
     */
    /**
     * Everything about a post's *current* published position that the edit is
     * about to destroy, for rebuildPost() to clean up afterwards.
     *
     * **Call this before the struct is applied and before terms are saved**, or
     * it records the new state and there is nothing left to compare against.
     * That is exactly how the term half went wrong: Post::saveTerms() refreshes
     * $post->categories/->tags in memory, so reading them inside rebuildPost()
     * — as this class did — yields the *new* set under a comment calling it the
     * old one, making the added-terms diff a no-op and leaving the archive a
     * post was removed from stale.
     *
     * @return array{dir:?string, neighbours:Post[], catIds:int[], tagIds:int[]}
     */
    private function xmlrpc_snapshot(Post $post, bool $wasPublished): array
    {
        if (!$wasPublished) {
            return ['dir' => null, 'neighbours' => [], 'catIds' => [], 'tagIds' => []];
        }

        return [
            'dir'        => $this->builder->postOutputDir($post->published_at, $post->slug),
            'neighbours' => array_values(array_filter([
                Post::findPrev($this->db, $post),
                Post::findNext($this->db, $post),
            ])),
            'catIds'     => array_map('intval', array_column($post->categories, 'id')),
            'tagIds'     => array_map('intval', array_column($post->tags, 'id')),
        ];
    }

    /**
     * @param array{dir?:?string, neighbours?:Post[], catIds?:int[], tagIds?:int[]} $before
     *        from xmlrpc_snapshot(). Empty for a create, which vacates nothing.
     */
    private function rebuildPost(Post $post, bool $wasPublished = false, array $before = []): void
    {
        $this->builder->removeVacatedPostOutput($before['dir'] ?? null, $post);

        if ($post->status === 'published' || $wasPublished) {
            $this->builder->buildPost($post);

            // Both positions, each post once. A post that moves in the timeline
            // leaves the pair it used to sit between still linking to where it
            // was; on an edit that does not move it these collapse to the same
            // two posts, which is what the de-duplication is for.
            $built = [];
            $newNeighbours = [Post::findPrev($this->db, $post), Post::findNext($this->db, $post)];
            foreach ([...($before['neighbours'] ?? []), ...$newNeighbours] as $neighbour) {
                if ($neighbour && $neighbour->id !== $post->id && !isset($built[$neighbour->id])) {
                    $this->builder->buildPost($neighbour);
                    $built[$neighbour->id] = true;
                }
            }

            $this->builder->rebuildSharedResources();

            // buildPost() covers the terms the post holds *now*. The ones it was
            // just removed from still show its card until they are rebuilt too,
            // so the archive pass is over the union of before and after.
            $newCatIds = array_map('intval', array_column($post->categories, 'id'));
            $newTagIds = array_map('intval', array_column($post->tags, 'id'));
            foreach (array_diff($before['catIds'] ?? [], $newCatIds) as $catId) {
                $this->builder->buildCategoryArchive((int) $catId);
            }
            foreach (array_diff($before['tagIds'] ?? [], $newTagIds) as $tagId) {
                $this->builder->buildTagArchive((int) $tagId);
            }
        } else {
            // Draft/scheduled save — no public file needed.
            $this->builder->buildPost($post);
        }
    }

    // ── Taxonomy helpers ──────────────────────────────────────────────────────────

    /**
     * Look up a category by name (case-insensitive slug match), creating it if absent.
     * Returns the category ID.
     */
    private function xmlrpc_upsert_category(string $name): int
    {
        $slug     = \CMS\Helpers::slugify($name);
        $existing = $this->db->selectOne("SELECT id FROM categories WHERE slug = :slug", [':slug' => $slug]);
        if ($existing) {
            return (int) $existing['id'];
        }
        return $this->db->insert('categories', ['name' => $name, 'slug' => $slug, 'description' => '']);
    }

    /**
     * Look up a tag by name (case-insensitive slug match), creating it if absent.
     * Returns the tag ID.
     */
    private function xmlrpc_upsert_tag(string $name): int
    {
        $slug     = \CMS\Helpers::slugify($name);
        $existing = $this->db->selectOne("SELECT id FROM tags WHERE slug = :slug", [':slug' => $slug]);
        if ($existing) {
            return (int) $existing['id'];
        }
        return $this->db->insert('tags', ['name' => $name, 'slug' => $slug]);
    }

    /**
     * Extract and save category + tag associations from a MetaWeblog struct's
     * 'categories' array (names) or a WordPress struct's 'terms' array.
     * Also handles a flat 'mt_tags' / 'mt_keywords' field (comma-separated names).
     */
    private function xmlrpc_save_terms(Post $post, array $struct): void
    {
        $categoryIds = [];
        $tagIds      = [];

        // MetaWeblog-style: 'categories' is an array of category name strings.
        if (!empty($struct['categories']) && is_array($struct['categories'])) {
            foreach ($struct['categories'] as $catName) {
                $catName = trim((string) $catName);
                if ($catName !== '') {
                    $categoryIds[] = $this->xmlrpc_upsert_category($catName);
                }
            }
        }

        // WordPress-style 'terms': two possible formats depending on the client:
        //   a) wp.editPost set format — struct of taxonomy → [term_ids]:
        //        {'category': [1, 2], 'post_tag': [3]}
        //   b) Round-tripped get format — sequential array of term-object structs:
        //        [{'term_id': '1', 'taxonomy': 'category', ...}, ...]
        if (!empty($struct['terms']) && is_array($struct['terms'])) {
            $terms = $struct['terms'];
            $isSequential = array_keys($terms) === range(0, count($terms) - 1);

            if (!$isSequential) {
                // Format (a): struct of taxonomy → [term_ids or term_names]
                foreach ($terms as $taxonomy => $termRefs) {
                    $taxonomy = strtolower(trim((string) $taxonomy));
                    if (!is_array($termRefs)) {
                        continue;
                    }
                    foreach ($termRefs as $ref) {
                        $id = (int) $ref;
                        if ($id > 0) {
                            if ($taxonomy === 'category') {
                                $categoryIds[] = $id;
                            } elseif ($taxonomy === 'post_tag') {
                                $tagIds[] = $id;
                            }
                        } else {
                            $name = trim((string) $ref);
                            if ($name !== '') {
                                if ($taxonomy === 'category') {
                                    $categoryIds[] = $this->xmlrpc_upsert_category($name);
                                } elseif ($taxonomy === 'post_tag') {
                                    $tagIds[] = $this->xmlrpc_upsert_tag($name);
                                }
                            }
                        }
                    }
                }
            } else {
                // Format (b): sequential array of term-object structs
                foreach ($terms as $term) {
                    if (!is_array($term)) {
                        continue;
                    }
                    $taxonomy = strtolower(trim((string) ($term['taxonomy'] ?? '')));
                    $termId   = isset($term['term_id']) ? (int) $term['term_id'] : 0;
                    $termName = trim((string) ($term['name'] ?? ''));

                    if ($taxonomy === 'category') {
                        if ($termId > 0) {
                            $categoryIds[] = $termId;
                        } elseif ($termName !== '') {
                            $categoryIds[] = $this->xmlrpc_upsert_category($termName);
                        }
                    } elseif ($taxonomy === 'post_tag') {
                        if ($termId > 0) {
                            $tagIds[] = $termId;
                        } elseif ($termName !== '') {
                            $tagIds[] = $this->xmlrpc_upsert_tag($termName);
                        }
                    }
                }
            }
        }

        // WordPress-style 'terms_names': struct of taxonomy → [names] (used by some WP clients).
        if (!empty($struct['terms_names']) && is_array($struct['terms_names'])) {
            foreach ($struct['terms_names'] as $taxonomy => $names) {
                $taxonomy = strtolower(trim((string) $taxonomy));
                if (!is_array($names)) {
                    continue;
                }
                foreach ($names as $name) {
                    $name = trim((string) $name);
                    if ($name === '') {
                        continue;
                    }
                    if ($taxonomy === 'category') {
                        $categoryIds[] = $this->xmlrpc_upsert_category($name);
                    } elseif ($taxonomy === 'post_tag') {
                        $tagIds[] = $this->xmlrpc_upsert_tag($name);
                    }
                }
            }
        }

        // mt_keywords / mt_tags: comma-separated tag names.
        $keywords = trim((string) ($struct['mt_keywords'] ?? $struct['mt_tags'] ?? ''));
        if ($keywords !== '') {
            foreach (array_filter(array_map('trim', explode(',', $keywords))) as $kw) {
                $tagIds[] = $this->xmlrpc_upsert_tag($kw);
            }
        }

        $post->saveTerms(array_unique($categoryIds), array_unique($tagIds));
    }

    // ── WordPress API helpers ─────────────────────────────────────────────────────

    /** Map CMS status → WordPress status string */
    private function wpStatus(string $s): string
    {
        return match ($s) {
            'published' => 'publish',
            'scheduled' => 'future',
            default     => 'draft',
        };
    }

    /** Map WordPress status string → CMS status */
    private function cmsStatusFromWp(string $s): string
    {
        return match ($s) {
            'publish' => 'published',
            'future'  => 'scheduled',
            default   => 'draft',
        };
    }

    /**
     * Build the WordPress-style struct for a single post.
     */
    private function wpPostToStruct(Post $post, string $siteUrl, string $timezone = ''): array
    {
        $pubAt = $post->published_at ?? $post->created_at;
        $url   = rtrim($siteUrl, '/') . '/' . Post::datePath($pubAt, $post->slug, $timezone) . '/';

        // WordPress requires post_status='publish' only for past-dated posts.
        // If a post is 'published' in our DB but its date is still in the future,
        // return 'future' so MarsEdit shows it in Scheduled (avoids display confusion).
        $wpStat = $this->wpStatus($post->status);
        if ($wpStat === 'publish' && $pubAt && strtotime($pubAt) > time()) {
            $wpStat = 'future';
        }

        return [
            'post_id'           => (string) $post->id,
            'post_title'        => $post->title,
            'post_content'      => $post->content,
            'post_excerpt'      => $post->excerpt ?? '',
            'post_name'         => $post->slug,
            'post_status'       => $wpStat,
            'post_type'         => 'post',
            'post_date'         => new \CMS\DateTimeValue(XmlRpc::isoDate($pubAt)),
            'post_date_gmt'     => new \CMS\DateTimeValue(XmlRpc::isoDate($pubAt)),
            'post_modified'     => new \CMS\DateTimeValue(XmlRpc::isoDate($post->updated_at)),
            'post_modified_gmt' => new \CMS\DateTimeValue(XmlRpc::isoDate($post->updated_at)),
            'link'              => $url,
            'guid'              => $url,
            'post_author'       => '1',
            'comment_status'    => 'closed',
            'ping_status'       => 'closed',
            'post_format'       => $this->xmlrpc_format_from_post($post),
            // See postToStruct() — a URL rather than an attachment id, so a
            // featured image that never came through the media library still
            // round-trips.
            'post_thumbnail'    => $this->xmlrpc_featured_url($post, $siteUrl),
            'terms'             => array_merge(
                array_map(fn($c) => [
                    'term_id'  => (string) $c['id'],
                    'name'     => $c['name'],
                    'slug'     => $c['slug'],
                    'taxonomy' => 'category',
                ], $post->categories),
                array_map(fn($t) => [
                    'term_id'  => (string) $t['id'],
                    'name'     => $t['name'],
                    'slug'     => $t['slug'],
                    'taxonomy' => 'post_tag',
                ], $post->tags)
            ),
            'custom_fields'     => [],
        ];
    }

    /**
     * Apply a WordPress-style content struct onto a Post object.
     * Mutates $post in place; does not save.
     */
    private function applyWpPostStruct(Post $post, array $struct, string $timezone): void
    {
        $this->xmlrpc_apply_kind($post, $struct);

        // See $this->applyStruct() — same field, same reason for living here.
        $this->xmlrpc_apply_featured_image($post, $struct);

        if (isset($struct['post_title'])) {
            $post->title = trim((string) $struct['post_title']);
        }

        if (isset($struct['post_content'])) {
            $post->content = (string) $struct['post_content'];
        }

        if (array_key_exists('post_excerpt', $struct)) {
            $ex = trim((string) $struct['post_excerpt']);
            $post->excerpt = $ex !== '' ? $ex : null;
        }

        // Slug — see $this->applyStruct() for the aside/standard derivation rules.
        $rawSlug = trim((string) ($struct['post_name'] ?? ''));
        if ($rawSlug === '') {
            if ($post->isNote()) {
                if ($post->slug === '') {
                    $rawSlug = Post::slugFromContent($post->content);
                }
            } elseif ($post->slug === '' || ctype_digit($post->slug)) {
                $rawSlug = $post->title;
            }
        }
        if ($rawSlug !== '') {
            $post->slug = $this->xmlrpc_resolve_slug($post, $rawSlug);
        }

        // Date
        $pubAt = XmlRpc::parseStructDate($struct, 'post_date', 'post_date_gmt', $timezone);

        // Status
        $wpStat = strtolower(trim((string) ($struct['post_status'] ?? 'publish')));

        if ($wpStat === 'draft') {
            $post->status = 'draft';
            if ($pubAt !== null) {
                $post->published_at = $pubAt;
            }
        } else {
            // 'publish', 'future', or anything else → attempt to publish/schedule.
            // Preserve the existing published_at when no date is supplied (edit case).
            $effectivePubAt     = $pubAt ?? $post->published_at ?? date('Y-m-d H:i:s');
            $post->published_at = $effectivePubAt;
            $post->status       = strtotime($effectivePubAt) > time() ? 'scheduled' : 'published';
        }
    }

    /**
     * Build the WordPress-style struct for a single page.
     */
    private function wpPageToStruct(Page $page, string $siteUrl): array
    {
        $url = rtrim($siteUrl, '/') . '/' . $page->slug . '/';

        return [
            'post_id'           => (string) ($page->id + self::PAGE_ID_OFFSET),
            'post_title'        => $page->title,
            'post_content'      => $page->content,
            'post_name'         => $page->slug,
            'post_status'       => $page->status === 'published' ? 'publish' : 'draft',
            'post_type'         => 'page',
            'post_date'         => new \CMS\DateTimeValue(XmlRpc::isoDate($page->created_at)),
            'post_date_gmt'     => new \CMS\DateTimeValue(XmlRpc::isoDate($page->created_at)),
            'post_modified'     => new \CMS\DateTimeValue(XmlRpc::isoDate($page->updated_at)),
            'post_modified_gmt' => new \CMS\DateTimeValue(XmlRpc::isoDate($page->updated_at)),
            'link'              => $url,
            'guid'              => $url,
            'post_author'       => '1',
            'menu_order'        => $page->nav_order,
            'comment_status'    => 'closed',
            'ping_status'       => 'closed',
            'custom_fields'     => [],
        ];
    }

    /**
     * Apply a WordPress-style struct onto a Page object.
     * Mutates $page in place; does not save.
     */
    private function applyWpPageStruct(Page $page, array $struct): void
    {
        if (isset($struct['post_title'])) {
            $page->title = trim((string) $struct['post_title']);
        }

        if (isset($struct['post_content'])) {
            $page->content = (string) $struct['post_content'];
        }

        // Slug
        $rawSlug = trim((string) ($struct['post_name'] ?? ''));
        if ($rawSlug === '' && $page->slug === '') {
            $rawSlug = $page->title;
        }
        if ($rawSlug !== '') {
            $page->slug = Page::resolveUniqueSlug($this->db, $rawSlug, $page->id);
        }

        // Status ('publish' → published, anything else → draft; no scheduling for pages)
        $wpStat = strtolower(trim((string) ($struct['post_status'] ?? '')));
        if ($wpStat !== '') {
            $page->status = $wpStat === 'publish' ? 'published' : 'draft';
        }

        // Nav order
        if (array_key_exists('menu_order', $struct)) {
            $page->nav_order = (int) $struct['menu_order'];
        }
    }

    /**
     * Run the appropriate Builder calls after saving a page.
     * Always rebuilds index when status changes (pages appear in nav).
     */
    private function rebuildPage(Page $page, bool $wasPublished): void
    {
        $this->builder->buildPage($page);

        if ($page->status === 'published' || $wasPublished) {
            $this->builder->buildIndex();
        }
    }
}
