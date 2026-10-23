=== Block Theme Ops ===
Contributors: aryanjasala
Tags: site editor, block theme, templates, theme.json, github
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find Site Editor changes that override your theme files, see the diff, reset them or send them to Git.

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
`wp block-theme-ops check`

`check` exits with 1 when any item is "theme changed", so a deploy script or CI job can stop and tell you.

= For AI agents =

On WordPress 6.9 and later the plugin registers abilities for the drift report, the diff of one item, reset and export, so agents can use them through the MCP adapter.

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

Best: `define( 'BLOCK_THEME_OPS_GITHUB_TOKEN', '...' );` in wp-config.php, or an environment variable with the same name. A token saved on the settings screen works too, but it is stored in the database. It is never shown again after you save it. Use a fine-grained token for one repository with read and write access to Contents and Pull requests.

= Can it open merge requests on GitLab? =

Not yet. The Git host sits behind an interface, and another one can be plugged in with the `block_theme_ops_git_provider` filter.

== Screenshots ==

1. Appearance > Theme Drift: counts per status and every Site Editor change of the active theme.
2. One template part: the database copy against the theme file now, and what the theme changed since the edit.
3. A pull request opened on GitHub with the selected Site Editor changes.
4. The notice after a theme update leaves a Site Editor change behind.

== Changelog ==

= 1.0.0 =
* First release.
