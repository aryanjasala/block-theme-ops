# Reference

## How the status is decided

Every template, template part and global styles post saved in the Site Editor for the active theme is an item. Customized plugin templates (such as WooCommerce's) and the empty global styles post core creates when the Site Editor opens are left out.

| Status | When |
| --- | --- |
| `theme-changed` | The theme file changed after the database copy was saved. The site ignores the newer file |
| `customized` | Changed in the Site Editor, and the theme file has not changed since |
| `db-only` | No theme file behind it, such as a custom template made in the Site Editor |
| `identical` | The database copy shows the same as the theme file. Safe to reset |

How it is worked out:

1. Each time a template, part or global styles are saved, Block Theme Ops stores a **snapshot** of the theme file (its contents and a hash) in post meta. When the file's hash later differs from the snapshot, the theme changed after the edit.
2. While the file differs from the snapshot, saving again in the Site Editor keeps the old snapshot. The new save does not bring in the theme's changes, so the warning stays until someone resets or keeps the item.
3. **Customizations from before the plugin was active** have no snapshot. For those the dates decide: a theme file newer than the database copy is `theme-changed`, marked **probably**. The first check after activation stores a baseline snapshot, so every later change is certain.
4. **Identical** compares what the markup shows, not its formatting: whitespace, attribute order and the `theme` attribute the Site Editor adds to template part blocks are ignored.
5. **Global styles** are a layer of values on top of theme.json, not a copy of it. They count as `theme-changed` only when the theme changed a value the user changed too, and as `identical` when every value they set equals the theme's.

Templates and parts are looked up in the active theme first, then in its parent, in `templates/` and `parts/` (or the older `block-templates/` and `block-template-parts/`). Global styles compare against the active theme's theme.json, or the parent's when the child has none.

## Reset, undo and keep

- **Reset** deletes the database copy of a template or part, or empties the global styles layer, so the theme file takes over. A backup goes into a private `bto_backup` post first. Items without a theme file cannot be reset.
- **Undo** puts the copy back with its original post ID, theme, area and snapshot, for 30 days by default (`block_theme_ops_backup_days`). It refuses when someone customized the item again since the reset, or when the same database copy is back already, say through an undo in an activity log. The Backups tab says which one up front, instead of offering Undo.
- **Keep** marks the copy as wanted. It drops out of the list, the notice, Site Health and the email until the theme file changes again. **List again** undoes it.

## Checks and alerts

The theme files are checked right after WordPress updates or replaces the active theme or its parent (`upgrader_process_complete`), once a day by WP-Cron for deploys that bypass WordPress, and on `wp block-theme-ops check`. Items that went `theme-changed` since the last check, and are not kept, raise:

- a notice for everyone with the capability on the Dashboard, Themes, Site Editor and Updates screens (`block_theme_ops_notice_screens`), and a count on the Theme Drift menu, until they are reset, kept or dismissed
- a Site Health recommendation
- an email to the site admin, when turned on under **Settings**

Each item is reported once per change of its file.

## Export

The zip and the pull request hold the items as theme files, by path inside the theme:

| Item | File |
| --- | --- |
| Template | `templates/<slug>.html`, without the `theme` attribute on template part blocks |
| Template part | `parts/<slug>.html`, the same way |
| Global styles | `theme.json`: the active theme's theme.json with the changes merged in. Lists such as palettes are replaced whole, as core does |

The zip puts them in a folder named after the theme. The pull request puts them under the **theme folder in the repository** you set, as one commit on a new branch (`block-theme-ops/<theme>-<date>-<time>`), through the GitHub API: read the base branch, create a tree and a commit, create the branch, open the pull request. The description lists each item, its file, its status, who last edited it and when.

The token comes from, in order: the `BLOCK_THEME_OPS_GITHUB_TOKEN` constant, an environment variable with that name, or the one saved on the settings screen. A saved token is never shown again. Until a repository and a token are set, **Open pull request** stays disabled, with a link to the settings. Use a fine-grained token for the one repository, with read and write access to **Contents** and **Pull requests**.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `block_theme_ops_capability` | filter | `( string $capability )`. Who may use Theme Drift and the abilities. Default `edit_theme_options` |
| `block_theme_ops_post_types` | filter | `( string[] $post_types )`. Tracked types. Default `wp_template`, `wp_template_part`, `wp_global_styles`. Other types are dropped |
| `block_theme_ops_status` | filter | `( string $status, array $item )`. Change one item's status. `db-only` is only allowed for items without a file, and the other statuses only for items with one |
| `block_theme_ops_check_schedule` | filter | `( string $recurrence )`. How often the scheduled check runs. Default `daily`. Must be a schedule from `wp_get_schedules()` |
| `block_theme_ops_notify_recipients` | filter | `( string[] $emails, array[] $items )`. Who gets the email. Default the site admin email |
| `block_theme_ops_notify_email` | filter | `( array $email, array[] $items )`. `to`, `subject`, `message` and `headers` before `wp_mail()`. An empty subject or message sends nothing |
| `block_theme_ops_notice_message` | filter | `( string $message, array[] $items )`. The admin notice text. Empty hides it |
| `block_theme_ops_notice_screens` | filter | `( string[] $screen_ids )`. Screens that show the notice. Default `dashboard`, `themes`, `site-editor` and `update-core`. Theme Drift itself never shows it. An empty list shows it nowhere; Site Health still has it |
| `block_theme_ops_undo_blocked_message` | filter | `( string $message, string $reason, array $backup )`. What the Backups tab and Undo say when a reset cannot be undone. `$reason` is `restored` (the same copy is back already) or `customized` (customized again since). Empty falls back to the default |
| `block_theme_ops_user_name` | filter | `( string $name, int $user_id )`. The name shown for whoever saved, edited or reset an item. Default the display name, `System` when no user was logged in (WP-CLI, WP-Cron, another plugin's undo) or `Deleted user #ID`. Empty falls back to the default |
| `block_theme_ops_backup_days` | filter | `( int $days )`. How long a reset can be undone. Default 30, kept between 1 and 3650 |
| `block_theme_ops_cleanup_batch` | filter | `( int $batch )`. Expired backups one daily run deletes. Default 50, kept between 1 and 500 |
| `block_theme_ops_export_files` | filter | `( array $files, array[] $items, string $context )`. Path => contents before the zip or pull request. `$context` is `zip`, `pr`, `cli` or `ability`. Paths that leave the theme folder are dropped |
| `block_theme_ops_github_api_url` | filter | `( string $url )`. The API address, for GitHub Enterprise Server or a test double. Default `https://api.github.com` |
| `block_theme_ops_github_request_args` | filter | `( array $args, string $method, string $endpoint )`. `wp_remote_request()` arguments of every GitHub call |
| `block_theme_ops_pr_title` | filter | `( string $title, array[] $items )`. Pull request title and commit message |
| `block_theme_ops_pr_body` | filter | `( string $body, array[] $items, array $files )`. Pull request description, in Markdown |
| `block_theme_ops_branch_name` | filter | `( string $branch, array[] $items )`. The new branch. Names Git would refuse fall back to the default |
| `block_theme_ops_git_provider` | filter | `( Provider $provider, Settings $settings )`. Another Git host. Return an `AryanX\BlockThemeOps\Git\Provider` |
| `block_theme_ops_drift_detected` | action | `( array[] $items, string $context )`. A check found items newly `theme-changed`. `$context` is `upgrade`, `cron` or `cli` |
| `block_theme_ops_checked` | action | `( array[] $items, string $context )`. After every check, with every item |
| `block_theme_ops_snapshot_saved` | action | `( int $post_id, array $snapshot )`. A snapshot of a theme file was stored |
| `block_theme_ops_reset` | action | `( array $item, int $backup_id )`. An item was reset to its theme file |
| `block_theme_ops_reset_undone` | action | `( array $backup, int $post_id )`. A reset was undone |
| `block_theme_ops_acknowledged` | action | `( array $item )`. Someone chose Keep |
| `block_theme_ops_backups_expired` | action | `( int[] $backup_ids )`. Backups past their undo window were deleted |
| `block_theme_ops_exported` | action | `( array $files, array[] $items, string $context )`. Items were exported |
| `block_theme_ops_pull_request_opened` | action | `( string $url, array[] $items, array $files )`. A pull request was opened |

```php
// Tell the team channel when a deploy leaves Site Editor changes behind.
add_action( 'block_theme_ops_drift_detected', function ( $items, $context ) {
	my_team_alert( sprintf( '%d template(s) ignore the new theme files: %s', count( $items ), implode( ', ', wp_list_pluck( $items, 'key' ) ) ) );
}, 10, 2 );

// Send the email to the developers, not the site owner.
add_filter( 'block_theme_ops_notify_recipients', fn() => array( 'dev@agency.example' ) );

// The repository keeps themes in a monorepo with its own branch naming.
add_filter( 'block_theme_ops_branch_name', fn( $branch ) => 'site-editor/' . gmdate( 'Y-m-d' ) );

// Also export the global styles as a style variation, next to theme.json.
add_filter( 'block_theme_ops_export_files', function ( $files ) {
	if ( isset( $files['theme.json'] ) ) {
		$files['styles/site-editor.json'] = $files['theme.json'];
	}
	return $files;
} );
```

Each item is an array: `id` (post ID), `key` (`templates/<slug>`, `parts/<slug>` or `styles`), `type`, `slug`, `title`, `theme`, `file` (path inside the theme, empty when none), `file_theme` (the child or the parent), `status`, `probable`, `kept`, `modified` and `file_modified` (Unix time), `author` and `author_name` (who last saved it, or `System` when no user was logged in).

JavaScript, through `wp.hooks`, on Appearance > Theme Drift:

| Hook | Type | Use |
| --- | --- | --- |
| `blockThemeOps.confirmMessage` | filter | `( string message, { action, items } )`. The question asked before a reset or a pull request. Return `''` to skip it |
| `blockThemeOps.beforeSubmit` | action | `( { action, items } )`. A Theme Drift form is about to be sent. `action` is `reset`, `keep`, `unkeep`, `export`, `pr`, `undo` or `reset_identical`; `items` are post IDs |

```js
wp.hooks.addFilter( 'blockThemeOps.confirmMessage', 'my-plugin', ( message, details ) => ( 'reset' === details.action ? 'Did you export this first?' : message ) );
```

## Abilities

On WordPress 6.9 and later, Block Theme Ops registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. All of them need the Theme Drift capability.

| Ability | Does |
| --- | --- |
| `block-theme-ops/get-drift-report` | Read-only. Every item with its status, and the counts. Input: `status`, `include_kept` |
| `block-theme-ops/get-item-diff` | Read-only. The three versions of one `item` and unified diffs: `reset` (file now to database), `theme_changes` (snapshot to file now) and `customization` (snapshot to database) |
| `block-theme-ops/export-changes` | Read-only. The theme files for `items`, by path. Default: every item that is not identical |
| `block-theme-ops/reset-to-theme` | Destructive. Resets one `item`, or every identical one with `identical: true`, keeping backups. Returns the backup IDs and when the undo window ends |

An `item` is a post ID, `templates/<slug>`, `parts/<slug>`, `styles` or a bare slug.

## WP-CLI

```sh
wp block-theme-ops status [--status=<status>] [--kept] [--format=table|csv|json|yaml|ids|count]
wp block-theme-ops diff <item> [--against=file|theme|snapshot]
wp block-theme-ops reset <item>... [--yes]
wp block-theme-ops reset --identical [--yes]
wp block-theme-ops undo <backup>
wp block-theme-ops backups [--format=table|csv|json|yaml|ids|count]
wp block-theme-ops keep <item>... [--undo]
wp block-theme-ops export [<item>...] [--zip=<file>] [--pr] [--format=table|json]
wp block-theme-ops check
```

`check` runs the drift check now and exits with 1 when any item is `theme-changed` and not kept, so a deploy script can stop:

```sh
wp block-theme-ops check || echo "Site Editor changes hide the new theme files"
```

## PHP API

```php
$plugin = AryanX\BlockThemeOps\Plugin::instance();

$plugin->drift()->items( array( 'status' => 'theme-changed', 'kept' => false ) );
$item = $plugin->drift()->get( 'parts/header' );
$plugin->operations()->reset( $item );           // array( 'item' => ..., 'backup' => 123 ) or WP_Error
$plugin->operations()->undo( 123 );
$plugin->exporter()->files( array( $item ), 'cli' );
$plugin->checker()->run( 'cli' );                // items newly theme-changed
```

## Data

| Where | Contents |
| --- | --- |
| `block_theme_ops_settings` option | Email on or off, repository, base branch, theme folder and the saved token |
| `block_theme_ops_alerts` option | Post IDs of items waiting for review, and what found them |
| `_block_theme_ops_snapshot` post meta | The theme file at the time of the edit: contents, hash, path and time |
| `_block_theme_ops_editor` post meta | User ID of whoever last saved the item in the Site Editor |
| `_block_theme_ops_kept`, `_block_theme_ops_notified` post meta | Hash of the theme file when it was kept, and when it was last reported |
| `bto_backup` posts | Reset items, with the user IDs of who last edited and who reset them, until the undo window ends |
| `block_theme_ops_check` cron event | The scheduled check |

Uninstalling removes all of it on every site of a network. The Site Editor changes themselves stay.

## Privacy

The editor and backup user IDs take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**, and **Settings > Privacy** gets suggested policy text. Opening a pull request sends the exported files and the editors' display names to GitHub, only when an administrator asks for it.

## Multisite

Everything works per site: each site has its own items, settings, token and alerts.
