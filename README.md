Easy Admin (module for Omeka S)
===============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Easy Admin] is a module for [Omeka S] that allows to manage Omeka from the
admin interface, without access to the server.

- Files
  - bulk upload of files in the item form, without the limits of the server
  - private directories by user for uploads and imports
  - file manager
- Interface
  - buttons "Public view" and "Previous/Next" in the resource pages
  - filter and navigation in the settings pages
  - color of the admin sidebar, to distinguish a test instance
  - thumbnails loaded only when they are displayed, so long pages load quickly
  - button "Apply" in the config forms of the modules
- Modules and themes
  - install modules and themes, or a [curated selection of modules]
  - fix the version of a module whose files were rolled back
- Maintenance
  - maintenance mode for the public and the admin interface
  - checks and fixes of the files and of the database (see below)
  - backup of the database and of the files
  - clear the php caches
  - adapt the settings after a copy of the production database
- Security
  - audit of the security and of the privacy
  - protection of the emails on a test copy
- Tasks
  - run any job of any module, from the admin interface or the command line
  - diagnose the background jobs (php-cli)

The checks and fixes include:

- files: missing or useless files, recovery of the original files from a
  directory, rebuild of the thumbnails, sizes, hashes, media types, dimensions
  for module [Iiif Server], renaming of the stored files, empty directories
- database: positions of the media, titles, primary media, encoding of the
  values (iso-8859 to utf-8), orphan rows, dead jobs, size of the sessions and
  of the logs, useless tables left by removed modules
- loop on all resources, for example to apply a new setting

The feature "content lock" was moved to the module [Lock Edit] since version
3.4.31.

Other modules add their own tasks:

- [Compilatio]: send files to compilatio.net, an anti-plagiarism app, and append
  the urls of the checked files
- [Guest]: reset the acceptance of the conditions
- [Iiif Server]: convert the old tables of contents
- [Lock Edit]: check and reset the content locks
- [Reference]: reindex the references
- [Thesaurus]: reindex the thesaurus
- [Zip]: create the zips


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

With big bases or on shared hosts, it is recommended to install the optional
module [Job Queue] to avoid to overload the server when backuping or recreating
derivate thumbnails.

The module [Cron] is recommended for advanced scheduling, but not required: the
module can clean sessions and run scheduled tasks independently via page loads.

The module uses external libraries, so use the release zip to install it, or use
and init the source.

* From the zip

Download the last release [EasyAdmin.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `EasyAdmin`, go to the root of the module, and run:

```sh
composer install --no-dev
```

* For test

The module includes a test suite. Run it from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/EasyAdmin/phpunit.xml --testdox
```


Usage
-----

### Main settings

The module adds options in the main settings:

- buttons in the resource pages (see below);
- allow the reviewer to delete any resource;
- maintenance mode;
- quick button to create a resource template;
- button "Apply" on every module config form, to save and stay on the form;
- base URI of the files and emails of a test copy (section "Maintenance and
  tests", see below).

### Bulk upload

Files can be uploaded in the resource form without the limits of the server on
the [size or number of files]: select "Files" in the list of media. A separate
form allows to upload files for future imports.

Some characters are forbidden in the file names, and the media types and the
extensions are checked according to the main settings.

The uploaded files can be listed and deleted in the file manager: menu "Easy
Admin" in the left sidebar, tab "File manager". Only the directories set in the
main settings can be browsed, inside the directory "files/" and outside the
directories managed by Omeka. An option of the config form allows any directory
inside "files/". Another one, set in the file "config/local.config.php" with the
key `easyadmin_local_path_any`, allows any writable directory: **warning**, it
has security implications, so check the rights or use it only temporarily.

### Directories by user

An option allows a private directory for each user who can create items
(author, reviewer, editor and administrators):

- each user has a directory `files/userdata/{user id}/`, created on first use;
- a user sees and uses only their own directory;
- the administrators can browse and download the files of all users, but they
  cannot upload or delete files in the directory of another user;
- the directories cannot be read from the web: the files are downloaded through
  the file manager, after login, and can still be imported by their path, that
  is displayed in the file manager.

When the option is disabled, the directories are kept on the disk.

### Buttons in resource pages

The buttons can be enabled in the main settings:

- "Public view": link to the resource page in the default site of the user or in
  the default site. Omeka S 4.1 has a similar button, but only for the items
  attached to a site.
- "Previous/Next": browse the resources like in Omeka Classic.

### Settings filter and navigation

The settings and the site settings pages can have a live filter and a section
navigation: filter the fields by label or value, jump to a section, display only
the modified or the default values, or only the text fields. Enable it with the
button "Enable filters" in the main settings page.

Since Omeka 4.3, the core has its own filter: the module adds only the features
that the core does not have.

### Color of the admin sidebar

The background color of the admin left sidebar can be set in the main settings,
for example to distinguish a test instance from the production one.

### Lazy loading of thumbnails

The thumbnails are loaded only when they appear on the screen, so a page with
many media is displayed quickly, in admin and in sites. A theme can still choose
another behavior for its own thumbnails. The lazy loading can be disabled in
main settings, with the option "Load all thumbnails at once".

### Checks and fixes

Go to the menu "Bulk Check", select the process, set the options if needed, and
submit. The results are written in the logs.

The processes cover all the resources, including the annotations and the digital
objects when the related modules are installed. For the checks of files, the
media and/or the digital objects can be selected.

The quick tasks, flagged "instant", display their result directly on the page.
The other ones run in background.

### Security and privacy

An audit lists the weaknesses of the install: sensitive directories of "files/"
readable from the web, executable php files, and possible leaks of user or
private data. It can protect the sensitive directories with a file ".htaccess",
that is applied on upgrade too.

The backups (database, modules, themes, config) are stored in "files/backup/"
and downloaded only after login.

A file ".htaccess" is read only by Apache. With nginx or another server, deny
the access to the sensitive directories in the config of the server, for
example:

```nginx
location ~ ^/files/(backup|userdata)/ {
    deny all;
}
```

### Background jobs (php-cli)

The background jobs are run by a separate php-cli, whose path and version may
differ from the php of the web server, in particular on shared hosting. A
diagnostic checks the php-cli, compares it with the php of the web server, and
recommends the right path when the configured one is invalid. Another action
runs a test job to check that the jobs start. A summary is added to the page
"System information".

### Useless database tables

A check lists the tables that are not used any more, for example the ones left
by removed modules. The tables of the inactive modules, of the core and of the
libraries used by modules are listed separately.

### Copy of a production database

After the copy of a production database on a test server, some settings must
be adapted. A task lists these settings, the config of the http client (proxy
and certificates) and the risks with the emails.

Indeed, a copy contains the real accounts and the real recipients of the
notifications, so they may receive the emails of the tests. The option "Emails
of a test copy" (section "Maintenance and tests") protects them without
modifying the database:

- redirect all the emails to one address, with the original recipients in the
  subject;
- send only to the domains of the team;
- send nothing and write the emails in the protected directory
  "files/mailcapture/".

An option adapts the settings for a test copy: title prefixed with « TEST »,
colored admin sidebar, errors displayed on screen, statistics trackers removed,
and grey images when the files are not served from another server. Another
option reverts them for the production, except the statistics trackers.

The base URI of the files can be replaced too, in order to serve the files from
another server, for example the production one, while the uploads stay local.
It is useful to work on a copy of the database without the files. When the
other server is protected, the images may be replaced by grey images or by
images of Wikimedia Commons matching a keyword. These images are downloaded
once in "files/fake/", so the visitors do not request the servers of Wikimedia.

For the keyword, use a free search like on Commons, preferably with English
words (landscape, lighthouse, flower, castle, bird…): all words must match, and
an empty keyword takes any image. Only the "Quality images" reviewed by the
community are used. See the [quality images by subject], or try a search on
Commons like `lighthouse incategory:"Quality images"`.

### Install and update modules and themes

Select a module or a theme and click "upload". See the lists of [modules] and of
[themes].

When the files of a module are rolled back without the database, the module is
not upgraded and stays in an inconsistent state. The page of the modules
displays the two versions, with a button "Fix version" to store the version of
the files in the database. The data are not modified, so use it only when the
rolled back versions did not change them.

The batch actions of the pages of the modules and of the themes allow to copy
the list of the addons or the urls to download them, for example to install the
same addons on another server.

### Tasks and cron tasks

The cron tasks are managed with the module [Cron]. Any task (job) can still be
run from this module, in the admin interface or with a script, for example to
harvest resources regularly with module [Bulk Import], to clean the sessions, or
to reindex the search engine with module [Search SolR]:

```sh
sudo -u www-data php '/path/to/omeka/modules/EasyAdmin/data/scripts/task.php' --task 'LoopItems' --user-id 1 --server-url 'https://example.org' --base-path '/'
```

| Argument             | Required    | Description                                                         |
|----------------------|-------------|---------------------------------------------------------------------|
| `-t`, `--task`       | yes         | class of the job ("EasyAdmin\Job\LoopItems") or its short name      |
| `-u`, `--user-id`    | yes         | id of the user, whose rights are used by the job                    |
| `-s`, `--server-url` | recommended | default "http://localhost"                                          |
| `-b`, `--base-path`  | recommended | path of Omeka in the url, default "/"                               |
| `-a`, `--args`       | no          | arguments of the job, as json, for example `'{"bulk_import_id": 1}'` |
| `-k`, `--as-task`    | no          | run as a simple task, without creating a job                        |

Run `task.php --help` for the full list. The job "LoopItems" saves all items, so
all the modules that use the api events process them, for example after a new
feature. The imports of [Bulk Import] and the exports of Bulk Export stored with
the option "Store job as a task" can be run the same way, with their id in the
args.

Notes:

- Use the user of the web server ("www-data") when the task creates files in
  "files/".
- Use the path of the script inside the directory of Omeka, not the real path
  of a shared module, else the instance is unknown. The page "Cron" of the
  module displays the right command.
- A job created in the admin interface can be run once with the script of
  Omeka: `php application/data/scripts/perform-job.php --job-id 1`.


Development
-----------

To append a new task:

- Add the name and label of the task via event `form.add_elements` on `\EasyAdmin\Form\JobsForm`.
- Eventually add a fieldset with specific options.
- Add the name of the job class and its params via event `easyadmin.job`, on
  class `\EasyAdmin\Controller\JobController`.

See an example in [Compilatio], [Reference], [Thesaurus], etc.


TODO
----

- [ ] Output results as tsv (`/files/check/tsv_date_time.tsv`) as BulkExport or in a table (done for missing file; to do for all processors).
- [ ] Output results as ods.
- [ ] Check files with the wrong extension.
- [x] Add width/height/duration as data for image/audio/video to avoid to get them each time (ready in modules [Iiif Server] and [Image Server]).
- [x] Remove old logs.
- [ ] A main cleaning task.
- [ ] Add a checkbox in page assets to optimise assets in batch.
- [ ] Add a deduplicator for assets (and replace assets used as resource thumbnails and in pages).
- [x] Dump database: see adminer.
- [x] Find a way to increase duration of csrf when there are very a large number of files to upload, instead of skipping csrf.
- [ ] Add a module setting for the csrf lifetime for bulk uploads and for main resource edit page.
- [x] check and remove orphan resource rows (rows in table `resource` without a matching row in `item`, `item_set` or `media`, which can happen after a crash or an incomplete deletion)


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

```sh
# database dump example
mysqldump -u omeka -p omeka | gzip > "omeka.$(date +%Y%m%d_%H%M%S).sql.gz"
```


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.

When the server is behind a proxy, a firewall or a specific infrastructure, the
access to the web (installation of modules, checks) may fail. Add the
certificates in the file "config/local.config.php" of Omeka, depending on the
linux distribution:

```php
    'http_client' => [
        // 'adapter' => null,
        'sslcapath' => '/usr/local/etc/ssl/certs',
        'sslcafile' => '/usr/local/etc/ssl/certs/ca.crt',
        // 'sslcapath' => '/etc/pki/tls/certs',
        // 'sslcafile' => '/etc/pki/tls/certs/ca-bundle.crt',
        'curloptions' => [],
    ],
```

In some cases, the path should be relative:

```php
    'http_client' => [
        // 'adapter' => null,
        'sslcapath' => '/usr/local/etc/ssl/certs/',
        'sslcafile' => 'ca.crt',
        // 'sslcapath' => '/etc/pki/tls/certs/',
        // 'sslcafile' => 'ca-bundle.crt',
        'curloptions' => [],
    ],
```

You can find more information on the params in [Laminas help] and [curl].


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

- Copyright Daniel Berthereau, 2017-2026 (see [Daniel-KM] on GitLab)

This module is a merge and improvement of previous modules [Easy Install],
[Next], [Maintenance], [Bulk Check] and [Generic]. The idea of [Easy Install]
comes from the plugin [Escher] for [Omeka Classic].

The curated selections of modules was implemented for the
[digital library Manioc] of the [Université des Antilles et de la Guyane].


[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
[Omeka S]: https://omeka.org/s
[curated selection of modules]: https://daniel-km.github.io/UpgradeToOmekaS/omeka_s_selections.html
[Easy Install]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyInstall
[Bulk Check]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkCheck
[Next]: https://gitlab.com/Daniel-KM/Omeka-S-module-Next
[Maintenance]: https://gitlab.com/Daniel-KM/Omeka-S-module-Maintenance
[installing a module]: https://omeka.org/s/docs/user-manual/modules/
[Compilatio]: https://gitlab.com/Daniel-KM/Omeka-S-module-Compilatio
[Guest]: https://gitlab.com/Daniel-KM/Omeka-S-module-Guest
[Iiif Server]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifServer
[Lock Edit]: https://gitlab.com/Daniel-KM/Omeka-S-module-LockEdit
[Reference]: https://gitlab.com/Daniel-KM/Omeka-S-module-Reference
[Thesaurus]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus
[Zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Zip
[Cron]: https://gitlab.com/Daniel-KM/Omeka-S-module-Cron
[EasyAdmin.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin/-/releases
[size or number of files]: https://github.com/omeka/omeka-s/issues/1785
[Laminas help]: https://docs.laminas.dev/laminas-http/client/adapters
[curl]: https://curl.se/libcurl/c/curl_easy_setopt.html
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin/-/work_items
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Bulk Import]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport
[Search SolR]: https://gitlab.com/Daniel-KM/Omeka-S-module-SearchSolr
[modules]: https://daniel-km.github.io/UpgradeToOmekaS/omeka_s_modules.html
[themes]: https://daniel-km.github.io/UpgradeToOmekaS/omeka_s_themes.html
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Escher]: https://github.com/AcuGIS/Escher
[Omeka Classic]: https://omeka.org/classic
[digital library Manioc]: http://www.manioc.org
[Université des Antilles et de la Guyane]: http://www.univ-ag.fr
[Job Queue]: https://gitlab.com/Daniel-KM/Omeka-S-module-JobQueue
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
[quality images by subject]: https://commons.wikimedia.org/wiki/Category:Quality_images_by_subject
