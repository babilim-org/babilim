# Directory submission notes

Three things live here: the email to send to the plugin review team before
(or together with) the submission, the text for the submission form's notes
field, and the checklist of steps only the account owner can do.

## 1. Email to plugins@wordpress.org

Subject: Questions before submitting Babilim (translation service client)

Hello,

Babilim is a plugin that finds the plugins and themes on a site that lack a
translation for the site's language and lets the site request one from the
Babilim service. Before we submit it, we would like to check four behaviors
against the plugin guidelines.

1. Translation files. The plugin downloads `.mo`, `.po` and `.json` files
   from the Babilim service and builds the `.l10n.php` files locally with
   `WP_Translation_File::transform()`. It never downloads PHP or JavaScript.
   Does this satisfy Guideline 8?

2. Free allowance. Each domain can use one theme and two plugins that are
   listed on WordPress.org for free; components not listed on WordPress.org,
   and anything beyond that allowance, need a paid license. The allowance is
   set by the service and may change; the plugin does not count or enforce
   any allowance itself, and translation files that were already downloaded
   keep working. The only thing that acts on the license kind without a
   click is the optional background auto-install: on the free plan it only
   updates components that already use a free slot, so a new slot is never
   taken without a click. The admin screen also reads the license kind to
   show the allowance note and a confirmation before a request, to offer
   the upgrade, to show the license label, and to choose which restore
   control to show (a restore-code box for a free site, a new-code button
   for a licensed one, none for a partner); it never counts or enforces
   the allowance.
   Is this service software under Guideline 6 rather than trialware under
   Guideline 5?

3. Payment. A license is bought on an external checkout page at babilim.org
   (Paddle is the merchant of record) that opens in a new tab. No third-party
   script is loaded in wp-admin. Does this satisfy Guideline 6 and
   Guideline 8?

4. Source upload. For a plugin or theme that is not hosted on wordpress.org,
   after a per-request confirmation screen the plugin uploads the
   entire plugin or theme directory to the service for translation. The
   confirmation screen and the readme disclose that keys, logs or customer
   data inside it would be uploaded too, that the service removes common
   sensitive files but cannot guarantee it finds everything, that the upload
   is kept until deletion is requested, that it is processed by AI models,
   and that the original text and the translations made from it are provided
   to other sites that install the same version. Is this disclosure
   sufficient?

Thank you,
The Babilim team

## 2. Submission form notes

Paste into the "Plugin description/notes" field:

Babilim looks at the plugins and themes installed on a site, finds the ones
missing a translation for the site's language, and lets the site request one
from the Babilim service. The "External services" section of readme.txt lists
every request the plugin makes and every field it sends. The plugin loads no
remote code: it only fetches translation data and builds `.l10n.php` files
locally. Nothing is sent to the service before the site owner consents, and
the plugin does not count or enforce any allowance; limits are decided by the
service and reflected in the UI. The only thing that acts on the license
kind without a click is the optional background auto-install, which on the
free plan only updates components that already use a free slot, so no new
slot is taken without a click; the admin screen also reads it to show the
allowance note, the license label and confirmation, to choose the restore
control, and to offer the upgrade. A Playground blueprint will be added to
the SVN assets directory (blueprints/blueprint.json) after approval.

## 3. Owner checklist

1. Confirm that `Contributors:` in `readme.txt` is the wp.org account
   `babilim` and that the version is 1.0.0 (the `Stable tag` in the readme is
   the reference). Enable two-factor authentication on the account, and make
   sure the account's email receives mail from plugins@wordpress.org.
2. Send the email above (it may go out together with the submission).
3. Run `make release-check`, then upload `build/babilim-<version>.zip` at
   https://wordpress.org/plugins/developers/add/ with the notes above.
4. After approval, on a machine with `svn` and `rsync`, run
   `make release RELEASE_ARGS=--dry-run`, then `make release`, then
   `git push origin v<version>` (push only this tag, never `--tags`).
5. Immediately open the plugin's Advanced View and turn on
   Release Confirmation. When the confirmation email arrives, confirm and
   choose "Delay auto-updates for 24 hours".
6. In Advanced View, open Test Preview to check the blueprint. Make the
   preview public only when it looks right.
7. Watch the support forum and the service's error rate for 24-48 hours.
