=== Block Theme Ops ===
Contributors: aryanjasala
Tags: site editor, block theme, templates, theme.json, github
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find Site Editor changes that override your theme files, see the diff, reset them, send them to Git, move them to another site or lock them.

== Description ==

When someone edits a template, a template part or the global styles in the Site Editor, WordPress saves a copy in the database. From then on the site uses that copy and ignores the theme file. Deploy a new version of the theme and nothing tells you the site still shows the old one.

Block Theme Ops lists every one of those database copies for the active theme and tells you how each one relates to its theme file:

* **Theme changed**: the theme file changed after the edit. The site ignores the newer file. This is the one to look at.
* **Customized**: changed in the Site Editor, and the theme file has not changed since.
* **Database only**: made in the Site Editor, with no theme file behind it.
* **Identical**: the database copy matches the theme file, so it is safe to reset.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= See what changed =

Appearance > Theme Drift shows the counts per status and a list of every item, with its theme file, when it was saved in the Site Editor and by whom. Open an item for three diffs:

* the database copy against the theme file now, which is what a reset throws away
* the theme file at the time of the edit against the theme file now, which is what the site is ignoring
* the theme file at the time of the edit against the database copy, which is the customization itself

To tell those apart, Block Theme Ops keeps a snapshot of the theme file every time a template, part or global styles are saved. For global styles it only reports a theme change when the theme changed a value the user changed too.

= Fix it =

* **Reset to theme file** deletes the database copy so the theme file takes over. A backup is kept for 30 days and Undo puts it back as it was.
* **Keep** says the database copy is on purpose. It drops out of the list and the alerts until its theme file changes again.
* **Reset all identical** cleans up copies that match their files in one go.

= Get the changes into Git =

* **Download a zip** laid out like the theme: `templates/*.html`, `parts/*.html` and a `theme.json` with the style changes merged in.
* **Open a GitHub pull request** with the changes as one commit on a new branch. The description lists each item, who last edited it and when. Set the repository, base branch and the theme's folder in the repository, and give it a fine-grained token, preferably through the `BLOCK_THEME_OPS_GITHUB_TOKEN` constant in wp-config.php.

= Move changes from staging to production =

Someone approved the new header on staging. Do not rebuild it by hand on the live site.

* On staging, Download zip. On the live site, open the Import tab and upload it. Nothing changes yet.
* The preview says, for each template, template part and the global styles, whether it is new, changed, identical or for another theme. If the live site has its own change to the same item, it is flagged, left unticked and shown as a second diff.
* Navigation menus are matched by slug, the staging address is rewritten to the live one, and media that is not in the live Media Library is listed instead of pointing at the wrong file.
* Choose the items and import. Everything it overwrites is backed up, and one Undo puts it all back for 30 days.

The zip is only read as text. Files with PHP tags, paths that leave the theme folder, symbolic links, anything that is not a template, a template part or theme.json, oversized files and zip bombs are skipped and listed. Nothing in the zip is run.

= Lock templates that belong to Git =

The header is deployed from the theme repository, and someone keeps "fixing" it in the Site Editor. The next deploy is then ignored.

* Lock single templates, template parts or the global styles, or everything that comes from the theme, on the Locks tab.
* A save from the Site Editor or the REST API is refused with a message that says where to change it, and the Site Editor shows a notice when someone opens a locked item, so nobody types for ten minutes first.
* Locks apply on production by default, and on the environments you tick. Nobody can override a lock unless a developer allows a capability. Turn the lock off on the Locks tab.
* Reset, import and Undo here still work.

= Know when a deploy left changes behind =

Right after WordPress updates the active theme, and once a day for deploys through Git, SFTP or rsync, Block Theme Ops compares the theme files. Items that went "theme changed" show in a notice on the Dashboard, Themes, Site Editor and Updates screens, in Site Health, and in an email if you turn that on.

= From the command line =

`wp block-theme-ops status [--status=<status>] [--format=<format>]`
`wp block-theme-ops diff <item> [--against=file|theme|snapshot]`
`wp block-theme-ops reset <item>... [--yes]`
`wp block-theme-ops reset --identical [--yes]`
`wp block-theme-ops undo <backup>`
`wp block-theme-ops keep <item>... [--undo]`
`wp block-theme-ops export [<item>...] [--zip=<file>] [--pr]`
`wp block-theme-ops import <zip> [--dry-run] [--only=<items>] [--yes]`
`wp block-theme-ops lock [<item>...] [--all] [--environments=<types>]`
`wp block-theme-ops unlock [<item>...] [--all]`
`wp block-theme-ops locks`
`wp block-theme-ops check`

`check` exits with 1 when any item is "theme changed", so a deploy script or CI job can stop and tell you.

= For AI agents =

On WordPress 6.9 and later the plugin registers abilities for the drift report, the diff of one item, reset, export, import (preview and apply), undo and locks, so agents can use them through the MCP adapter. Each one also checks the capability of the post it touches.

== External services ==

Block Theme Ops connects to the GitHub REST API (https://api.github.com) only when an administrator clicks "Open pull request" on Appearance > Theme Drift, or runs `wp block-theme-ops export --pr`. Nothing is sent otherwise, and nothing is sent on a schedule.

When it does, it sends the exported template, template part and theme.json files, the display names of the people who last edited them and when, the site's name and address, and the access token you set. It reads the base branch, creates a commit and a branch, and opens a pull request in the repository you set.

GitHub's terms of service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
GitHub's privacy statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Frequently Asked Questions ==

= Does it change anything on its own? =

No. Nothing changes on the site until you reset, keep or export something. Saves in the Site Editor and the checks only record snapshots of theme files, show the notice and, when you turn it on, send the email.

= Why "probably" on some items? =

Customizations saved before Block Theme Ops was active have no snapshot of the theme file from that time. For those it compares dates: a theme file newer than the database copy probably changed after the edit. The first check after activation takes a snapshot of each file, so later theme changes are certain.

= I edited the template again in the Site Editor. Why does it still say "theme changed"? =

Saving again does not bring in the theme's changes. The site still ignores the newer file. Reset it, or choose Keep if the database copy is what you want.

= Does it work with child themes? =

Yes. Templates and parts are looked up in the child theme first and then in the parent, the same way WordPress does. For global styles it compares the child theme's theme.json, or the parent's when the child has none.

= Where should the GitHub token come from? =

Best: `define( 'BLOCK_THEME_OPS_GITHUB_TOKEN', '...' );` in wp-config.php, or an environment variable with the same name. A token saved on the settings screen works too. It is stored encrypted in the database and never shown again after you save it. Use a fine-grained token for one repository with read and write access to Contents and Pull requests.

= Does import overwrite what is on the live site? =

Only what you choose, and only after the preview. Items the live site changed too are not ticked for you. Every item it overwrites is backed up first, and Undo restores them all for 30 days.

= Can a locked template still be changed? =

Not in the Site Editor or through the REST API on the environments where locks apply. Change the theme file and deploy it, or turn the lock off on the Locks tab. A lock does not remove a copy that was saved earlier: reset it from the Drift tab.

= Can it open merge requests on GitLab? =

Not yet. The Git host sits behind an interface, and another one can be plugged in with the `block_theme_ops_git_provider` filter.

== Screenshots ==

1. Appearance > Theme Drift: counts per status and every Site Editor change of the active theme.
2. One template part: the database copy against the theme file now, and what the theme changed since the edit.
3. A pull request opened on GitHub with the selected Site Editor changes.
4. The notice after a theme update leaves a Site Editor change behind.
5. The Import tab: what a zip from another site would change, item by item.
6. The Locks tab: which templates cannot be saved from the Site Editor.

== Changelog ==

= 1.0.0 =
* First release.
