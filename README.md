Easy Admin (module for Omeka S)
===============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Easy Admin] is a module for [Omeka S] that allows to manage Omeka from the
admin interface:

- content lock to avoid concurrent edition;
- install modules;
- update modules (in a future version);
- maintenance state for public and admin even when no migration;
- checks database and files;
- launch simple tasks, that can be any job of any module.

Checks and fixes that are doable:

- list files in the file system (original and thumbnails), but not in the
  database
- remove useless files in the files directory (moved to files/check)
- list files in the database, but not in the file system (for original and
  thumbnails)
- copy original files from a directory, for example after a disk crash or an
  inadvertent deletion; files are copied via the hash, and they can be anywhere
  in the directory or in subdirectories of the source path.
- rebuild derivative files
- remove empty directories in file system (original and thumbnails, mainly for
  module [Archive Repertory])
- check and update file size of media (required to fix Omeka installed before
  Omeka 1.2 ([omeka/omeka-s#1257]), or after a hard update of files)
- check and fix sha256 hashes of files
- check and fix positions of media (start from 1, without missing number)
- check and stop dead jobs (living in database, but non-existent in system)
- check the size of the database table of sessions and remove them
- check the size of the database table of logs and remove them
- check and fix the encoding (iso-8859 to utf-8) of resource values and page
  contents

And many more.


Installation
------------

This module requires the module [Log] and the optional module [Generic].

* From the zip

Download the last release [EasyAdmin.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `EasyAdmin`.

Uncompress files and rename module folder `EasyAdmin`.

Then install it like any other Omeka module and follow the config instructions.

See general end user documentation for [installing a module].

In some cases, your may need to add credentials in your config (omeka file "config/local.config.php"),
depending on your linux distribution:
```php
    'http_client' => [
        // 'adapter' => \Laminas\Http\Client\Adapter\Curl::class,
        'sslcapath' => '/usr/local/etc/ssl/certs',
        'sslcafile' => '/usr/local/etc/ssl/certs/ca.crt',
        // 'sslcapath' => '/etc/pki/tls/certs',
        // 'sslcafile' => '/etc/pki/tls/certs/ca-bundle.crt',
    ],
```

You can find more information on the params in [Laminas help].


Usage
-----

### Checks and fixes

Go to the menu "Bulk Check", select your process, set your options if needed,
and click the submit buttons. The results are available in logs currently.


### Install and update modules and themes

Simply select either the desired module or the desired theme and click "upload".

See more details on [modules] and [themes].

### Content lock

This feature is inspired by Drupal [Content Lock] mechanism and allows to block
concurrent editing: when a user is editing a resource, other users cannot edit
it until submission.

### Tasks and cron tasks

A script allows to run any job of any module from the command line, even if they
are not initialized in the admin interface. It’s useful to run one time tasks or
cron tasks, for example to harvest and update resources regularly with module
[Bulk Import], or to clean up sessions (see included jobs), or to reindex
resources in the search engine with module [Search SolR].

Here is the command line to use:

```sh
php /path/to/omeka/modules/EasyAdmin/data/scripts/task.php --help
```

In main cron tab or in the one of the user "www-data", you can add a task like
that:

```sh
/bin/su - www-data -c "php /path/to/omeka/modules/EasyAdmin/data/scripts/task.php --task 'EasyAdmin\Job\LoopItems' --user-id 1 --server-url 'https://example.org' --base-path '/'"
```

To use the command with the user "www-data" (or equivalent) may be required when
the task creates files inside Omeka "/files/" directory. If the task only uses
the database, it is usually not needed, but you need to take care of modules
that can create derivative or temp files. And you may need to take care of
escaping json arguments. Or use a sudo command:

```sh
sudo -u www-data php /path/to/omeka/modules/EasyAdmin/data/scripts/task.php --task 'BulkImport\Job\Import' --user-id 1 --server-url 'https://example.org' --base-path '/omeka-s' --args '{"bulk_import_id": 1}'
```

Required arguments are:
  - `-t` `--task` [Name] May be the full class of a job ("EasyAdmin\Job\LoopItems")
    or its basename ("LoopItems"). You should take care of case sensitivity and
    escaping "\" or quoting name on cli.
  - `-u` `--user-id` [#id] The Omeka user id is required, else the job won’t
    have any rights.

Recommended arguments:
  - `-s` `--server-url` [url] (default: "http://localhost")
  - `-b` `--base-path` [path] (default: "/") The url path to complete the server
    url.

Optional arguments:
  - `-a` `--args` [json] Arguments to pass to the task. Arguments are specific
    to each job. To find them, check the code, or run a job manually then check
    the job page in admin interface.
  - `-j` `--job` Create a standard job that will be checkable in admin interface.
    In any case, all logs are available in logs with a reference code. It allows
    to process some rare jobs that are not taskable too.

As an example, you can try the included job/task, for example "LoopItems" that
loops all items to save them. This task allows to update all items, so all the
modules that uses api events are triggered. This job can be use as a one-time
task that help to process existing items when a new feature is added in a
module:

```sh
php /path/to/omeka/modules/EasyAdmin/data/scripts/task.php --task 'LoopItems' --user-id 1 --server-url 'https://example.org' --base-path '/' --args '{}'
```

Another example: run a bulk import job whose config is stored. Indeed, because
the config of a bulk import may be complex, it is simpler to store it in the
admin interface with its option "Store job as a task".

```sh
php /path/to/omeka/modules/EasyAdmin/data/scripts/task.php --task 'BulkImport\Job\Import' --user-id 1 --server-url 'https://example.org' --base-path '/' --args '{"bulk_import_id": 1}'
```

Another example: reindex statistics after import of hits:
```sh
sudo -u www-data php /path/to/omeka/modules/EasyAdmin/data/scripts/task.php --task 'Statistics\Job\AggregateHits' --user-id 1 --server-url 'https://example.org' --base-path '/'
```

Note that for jobs created manually in the admin interface, you can run them
with the standard Omeka "perform-job.php". Of course, these job can be run one
time only:

```sh
php /path/to/omeka/application/data/scripts/perform-job.php --job-id 1 --server-url 'https://example.org' --base-path '/'
```


TODO
----

- [ ] Output results as tsv (`/files/check/tsv_date_time.tsv`) as BulkExport or in
  a table (done for missing file; to do for all processors).
- [ ] Check files with the wrong extension.
- [ ] Add width/height/duration as data for image/audio/video to avoid to get them
  each time (ready in modules [Iiif Server] and [Image Server]).
- [ ] Remove old logs.
- [ ] A main cleaning task.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


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

* Copyright Daniel Berthereau, 2017-2022 (see [Daniel-KM] on GitLab)

This module is a merge and improvement of previous modules [Easy Install], [Next],
[Maintenance] and [Bulk Check].
The idea of [Easy Install] comes from the plugin [Escher] for [Omeka Classic].


[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
[Omeka S]: https://omeka.org/s
[Easy Install]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyInstall
[Bulk Check]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkCheck
[Next]: https://gitlab.com/Daniel-KM/Omeka-S-module-Next
[Maintenance]: https://gitlab.com/Daniel-KM/Omeka-S-module-Maintenance
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/
[EasyAdmin.zip]: https://github.com/Daniel-KM/Omeka-S-module-EasyAdmin/releases
[Laminas help]: https://docs.laminas.dev/laminas-http/client/adapters
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin/issues
[Archive Repertory]: https://gitlab.com/Daniel-KM/Omeka-S-module-ArchiveRepertory
[omeka/omeka-s#1257]: https://github.com/omeka/omeka-s/pull/1257
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Log]: https://gitlab.com/Daniel-KM/Omeka-S-module-Log
[Content Lock]: https://www.drupal.org/project/content_lock
[Iiif Server]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifServer
[Image Server]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer
[modules]: https://daniel-km.github.io/UpgradeToOmekaS/omeka_s_modules.html
[themes]: https://daniel-km.github.io/UpgradeToOmekaS/omeka_s_themes.html
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Escher]: https://github.com/AcuGIS/Escher
[Omeka Classic]: https://omeka.org/classic
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
