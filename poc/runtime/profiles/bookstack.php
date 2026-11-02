<?php

declare(strict_types=1);

use PeakSim\Runtime\Guard\DbGuard;

// Knowledge about this app (BookStack, a Laravel documentation wiki) that its schema and its routes cannot give.
// A run uses it only when asked for (`--profile=bookstack`), and a result reached with it is reported as such, apart
// from one reached without.
//
// This is knowledge, not a rule of judgement: nothing here decides whether a count is flat or grows. The app is never
// changed and its seeder is never run. Each entry says why it is there, and where in the app that is.
$profile = [
    // Route argument => where its value comes from. The tool's rules cannot fill these: BookStack looks an entity up by
    // its slug in a query of its own, not by model binding, and a bare `{id}` names no table.
    'arguments' => [
        'id' => ['value' => 1, 'why' => 'a bare {id} names no table; the generated rows are numbered from 1, so row 1 exists in every table. The book delete route takes it as the book\'s slug (app/Entities/Controllers/BookController.php:208-210), which the fixup below sets to 1'],
        'slug' => ['value' => '1', 'why' => 'the slug is the lookup key of a book or a shelf (app/Entities/Queries/BookQueries.php:37-49); the fixup below sets it'],
        'bookSlug' => ['value' => '1', 'why' => 'the slug of the book a page or chapter is read in (ChapterQueries.php:34-51, PageQueries.php:48-64)'],
        'chapterSlug' => ['value' => '2', 'why' => 'the slug of a chapter (ChapterQueries.php:34-51); the fixup below sets it'],
        'pageSlug' => ['value' => '3', 'why' => 'the slug of a page (PageQueries.php:48-64); the fixup below sets it'],
        'pageId' => ['value' => 4, 'why' => 'a draft route looks a page up by its number and wants a draft of the login (app/Entities/Controllers/PageController.php:100-104, 483-488); the fixups below make row 4 one'],
        'revId' => ['value' => 1, 'why' => 'a revision is read by its number inside the page; the fixup below puts the revisions on the page of slug 3'],
        'socialDriver' => ['value' => 'github', 'why' => 'the callback takes the name of a login service the app knows (app/Access/Controllers/SocialController.php:60, app/Access/SocialDriverManager.php, app/Config/services.php); one of its names is used'],
        'category' => ['value' => 'features', 'why' => 'the settings page for a category exists when a view of that name does (app/Settings/SettingController.php:57-62, resources/views/settings/categories/features.blade.php)'],
    ],

    // Single UPDATE statements that give a generated row the meaning the app looks for. The app's seeder is not run.
    'fixups' => [
        [
            'name' => 'entity_types',
            'why' => 'every book, chapter, page and shelf is a row of one table told apart by its type column (database/migrations/2025_09_15_132850_create_entities_table.php:16-18, app/Entities/Models/Entity.php:84, EntityScope.php:14); the generated type is a made-up word, so no lookup finds an entity. The slug is the key of a lookup (a book and a shelf share the argument name slug), so it is set to the same short word for a kind',
            'sql' => "UPDATE entities SET type = CASE MOD(id, 5) WHEN 1 THEN 'book' WHEN 2 THEN 'chapter' WHEN 0 THEN 'bookshelf' ELSE 'page' END, slug = CASE MOD(id, 5) WHEN 1 THEN '1' WHEN 2 THEN '2' WHEN 0 THEN '1' ELSE '3' END, created_by = 1, owned_by = 1",
        ],
        [
            'name' => 'entities_sit_in_the_first_book',
            'why' => 'a chapter or a page is looked up inside its book by the book\'s slug, and its parent is its chapter when it has one (app/Entities/Models/BookChild.php:21-24); the generated book_id/chapter_id point at random numbers',
            'sql' => "UPDATE entities SET book_id = 1, chapter_id = NULL WHERE type IN ('chapter', 'page')",
        ],
        [
            'name' => 'login_is_admin',
            'why' => 'the permission checks of the image, settings and user pages pass for a role that holds the permission; the migrations give the role whose system_name is admin every permission, and the generated pivot gives the login random roles (app/Users/Models/User.php:156-160, app/Permissions/EntityPermissionEvaluator.php:157-161)',
            'sql' => "INSERT INTO role_user (user_id, role_id) SELECT 1, r.id FROM roles r WHERE r.system_name = 'admin' AND NOT EXISTS (SELECT 1 FROM role_user ru WHERE ru.user_id = 1 AND ru.role_id = r.id)",
        ],
        [
            'name' => 'joint_permissions_cleared',
            'why' => 'a lookup of a visible entity joins the table of precomputed permissions by (role, entity type, entity id) (app/Permissions/PermissionApplicator.php:99-111); the generated rows name made-up combinations, and the app rebuilds this table itself when permissions change (app/Permissions/JointPermissionBuilder.php)',
            'sql' => 'DELETE FROM joint_permissions',
        ],
        [
            'name' => 'joint_permissions_for_every_entity_and_role',
            'why' => 'what the app\'s own builder writes when no entity has its own permission: one row per role and entity, status implicit-allow (app/Permissions/PermissionStatus.php:8, JointPermissionBuilder.php:281)',
            'sql' => 'INSERT INTO joint_permissions (role_id, entity_type, entity_id, status, owner_id) SELECT r.id, e.type, e.id, 1, 1 FROM entities e CROSS JOIN roles r',
        ],
        [
            'name' => 'page_data_one_row_per_page',
            'why' => 'a page query joins the page data and drops a draft that is not the login\'s (app/Entities/Models/EntityScope.php:17-18, app/Permissions/PermissionApplicator.php:117-126); the generated rows name made-up page numbers. Row 4 is the one draft',
            'sql' => "DELETE FROM entity_page_data",
        ],
        [
            'name' => 'page_data_inserted',
            'why' => 'one row of page data for each page; the draft flag is set for row 4 only (see pageId above)',
            'sql' => "INSERT INTO entity_page_data (page_id, draft, template, revision_count, editor, html, text, markdown) SELECT id, IF(id = 4, 1, 0), 0, 0, 'wysiwyg', '', '', '' FROM entities WHERE type = 'page'",
        ],
        [
            'name' => 'images_are_gallery_images',
            'why' => 'the image edit page refuses an image whose type is not gallery or drawio (app/Uploads/Controllers/ImageController.php:159-163); the generated type is a made-up word',
            'sql' => "UPDATE images SET type = 'gallery'",
        ],
        [
            'name' => 'revisions_on_page_of_slug_3',
            'why' => 'a revision is looked up inside its page (app/Entities/Controllers/PageRevisionController.php); the generated page_id values are random',
            'sql' => "UPDATE page_revisions SET page_id = 3",
        ],
        [
            'name' => 'container_data_cleared',
            'why' => 'a book, chapter or shelf is read together with its description row (app/Entities/Models/EntityScope.php:19-24); the generated rows name made-up entity numbers, and a row without a description makes the reference rebuild fail on a null text (app/References/CrossLinkParser.php, regenerate-references)',
            'sql' => 'DELETE FROM entity_container_data',
        ],
        [
            'name' => 'container_data_inserted',
            'why' => 'one description row, empty, for each book, chapter and shelf',
            'sql' => "INSERT INTO entity_container_data (entity_id, entity_type, description, description_html) SELECT id, type, '', '' FROM entities WHERE type <> 'page'",
        ],
    ],
];

$profile['after_generation'] =
    /** @return array{fixups: array<string, int>} */
    static function (PDO $pdo, string $database) use ($profile): array {
        DbGuard::assertDbName($database);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($current !== $database) {
                throw new RuntimeException(sprintf('refused: the connection is on %s, not on the measurement database %s', is_string($current) ? $current : 'no database', $database));
            }
        }
        $summary = ['fixups' => []];
        foreach ($profile['fixups'] as $fix) {
            $summary['fixups'][$fix['name']] = (int) $pdo->exec($fix['sql']);
        }

        return $summary;
    };

return $profile;
