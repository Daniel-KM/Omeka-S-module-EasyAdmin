Easy Admin (module for Omeka S)
===============================

[Easy Admin] is a module for [Omeka S] that allows to manage Omeka from the
admin interface:

- launch regular simple tasks inside Omeka;
- install modules;
- update modules;
- checks database and files.

Note: install/update modules is currently managed by module [Easy Install] and
the checks is currently managed by module [Bulk Check]. They will be included
soon.


Installation
------------

Uncompress files and rename module folder `EasyAdmin`.

Then install it like any other Omeka module and follow the config instructions.

See general end user documentation for [Installing a module].


Usage
-----

### Cron tasks

A script allows to run jobs from the command line, even if they are not
initialized. It’s useful to run cron tasks. See required and optional arguments:

```sh
php /path/to/omeka/modules/EasyAdmin/data/scripts/task.php --help
```

In your cron tab, you can add a task like that:

```sh
/bin/su - www-data -C "php /var/www/omeka/modules/EasyAdmin/data/scripts/task.php" --task MyTask --user-id 1
```


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


[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
[Omeka S]: https://omeka.org/s
[Easy Install]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyInstall
[Bulk Check]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkCheck
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Cron/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
