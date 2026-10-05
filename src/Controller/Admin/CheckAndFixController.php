<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class CheckAndFixController extends AbstractActionController
{
    /**
     * Processes quick enough to run in the web process, with instant feedback.
     *
     * They are dispatched as jobs anyway, so the job log and history are kept,
     * but with the synchronous strategy, so they do not need the php-cli and
     * the result is displayed immediately. Only processes doing bounded sql or
     * looping on a fixed list (tables, stalled jobs) belong here: anything
     * looping on resources or files must stay a background job.
     */
    const QUICK_PROCESSES = [
        'db_job_check',
        'db_job_fix',
        'db_job_fix_all',
        'db_session_check',
        'db_log_check',
        'db_customvocab_missing_itemsets_check',
        'db_customvocab_missing_itemsets_clean',
        'db_resource_orphans_check',
        'db_resource_orphans_fix',
    ];

    /**
     * Enable the settings enhancements (filter and section navigation), from
     * the button added on the settings pages when they are disabled.
     */
    public function enableSettingsEnhancementsAction()
    {
        $request = $this->getRequest();
        if ($request->isPost()) {
            $validator = new \Laminas\Validator\Csrf(['name' => 'easyadmin_enable_enhancements']);
            if ($validator->isValid((string) $request->getPost('csrf'))) {
                $this->settings()->set('easyadmin_settings_enhancements', true);
                $this->messenger()->addSuccess('The settings filter is now enabled.'); // @translate
            } else {
                $this->messenger()->addError('Invalid or expired CSRF token.'); // @translate
            }
        }
        $referer = $request->getHeader('Referer');
        return $this->redirect()->toUrl($referer
            ? $referer->getFieldValue()
            : $this->url()->fromRoute('admin'));
    }

    public function indexAction()
    {
        /** @var \EasyAdmin\Form\CheckAndFixForm $form */
        $form = $this->getForm(\EasyAdmin\Form\CheckAndFixForm::class);
        $view = new ViewModel([
            'form' => $form,
        ]);

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $view;
        }

        $params = $request->getPost();

        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $params = $form->getData();
        unset($params['csrf']);

        // Only first process is managed.
        $process = null;
        foreach ($params as $value) {
            if (is_array($value) && !empty($value['process'])) {
                $process = $value['process'];
                break;
            }
        }

        if (empty($process)) {
            $this->messenger()->addWarning('No process submitted.'); // @translate
            return $view;
        }

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $job = null;
        $dispatcher = $this->jobDispatcher();
        // A quick process runs in the web process for an instant feedback; the
        // other ones are dispatched in background with the default strategy.
        $isQuick = in_array($process, self::QUICK_PROCESSES, true);
        // The messenger strategy displays the log of the job on the page.
        $strategy = $isQuick
            ? $this->getEvent()->getApplication()->getServiceManager()
                ->get('Common\Job\DispatchStrategy\SynchronousMessenger')
            : null;
        $defaultParams = [
            'process' => $process,
            'entity_types' => $params['files_checkfix']['entity_types'] ?? ['media'],
        ];

        switch ($process) {
            case 'files_excess_check_full':
                $defaultParams['include_derivatives'] = true;
                // no break
            case 'files_excess_check':
            case 'files_excess_move':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileExcess::class, $defaultParams + ($params['files_checkfix']['files_excess'] ?? []));
                break;
            case 'files_missing_check_full':
                $params['files_checkfix']['files_missing']['include_derivatives'] = true;
                // no break
            case 'files_missing_check':
            case 'files_missing_fix':
            case 'files_missing_fix_db':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMissing::class, $defaultParams + $params['files_checkfix']['files_missing']);
                break;
            case 'files_derivative':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDerivative::class, $defaultParams + $params['files_checkfix']['files_derivative']);
                break;
            case 'files_derivative_file_system':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDerivativeFileSystem::class, $defaultParams + $params['files_checkfix']['files_derivative_file_system']);
                break;
            case 'files_media_no_original':
            case 'files_media_no_original_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMediaNoOriginal::class, $defaultParams);
                break;
            case 'dirs_excess':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DirExcess::class, $defaultParams);
                break;
            case 'files_size_check':
            case 'files_size_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileSize::class, $defaultParams);
                break;
            case 'files_hash_check':
            case 'files_hash_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileHash::class, $defaultParams);
                break;
            case 'files_storage_check':
            case 'files_storage_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileStorage::class, $defaultParams);
                break;
            case 'files_media_type_check':
            case 'files_media_type_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMediaType::class, $defaultParams);
                break;
            case 'files_dimension_check':
            case 'files_dimension_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDimension::class, $defaultParams);
                break;
            case 'media_position_check':
            case 'media_position_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\MediaPosition::class, $defaultParams);
                break;
            case 'db_resource_invalid_check':
            case 'db_resource_invalid_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceInvalid::class, $defaultParams);
                break;
            case 'db_loop_save':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbLoopResources::class, $defaultParams + $params['resource_values']['db_loop_save']);
                break;
            case 'db_resource_incomplete_check':
            case 'db_resource_incomplete_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceIncomplete::class, $defaultParams);
                break;
            case 'db_resource_orphans_check':
            case 'db_resource_orphans_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceOrphans::class, $defaultParams, $strategy);
                break;
            case 'db_item_no_value':
            case 'db_item_no_value_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbItemNoValue::class, $defaultParams);
                break;
            case 'db_utf8_encode_check':
            case 'db_utf8_encode_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbUtf8Encode::class, $defaultParams + $params['resource_values']['db_utf8_encode']);
                break;
            case 'db_value_clean_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbValueClean::class, $defaultParams + $params['resource_values']['db_value_clean']);
                break;
            case 'db_resource_title_check':
            case 'db_resource_title_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceTitle::class, $defaultParams + $params['resource_values']['db_resource_title']);
                break;
            case 'db_item_primary_media_check':
            case 'db_item_primary_media_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbItemPrimaryMedia::class, $defaultParams);
                break;
            case 'db_value_annotation_template_check':
            case 'db_value_annotation_template_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbValueAnnotationTemplate::class, $defaultParams);
                break;
            case 'db_job_check':
            case 'db_job_fix':
            case 'db_job_fix_all':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbJob::class, $defaultParams, $strategy);
                break;
            case 'db_session_check':
            case 'db_session_clean':
            case 'db_session_recreate':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbSession::class, $defaultParams + $params['database']['db_session'], $strategy);
                break;
            case 'db_log_check':
            case 'db_log_clean':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbLog::class, $defaultParams + $params['database']['db_log'], $strategy);
                break;
            case 'db_customvocab_missing_itemsets_check':
            case 'db_customvocab_missing_itemsets_clean':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbCustomVocabMissingItemSets::class, $defaultParams + $params['database']['db_customvocab_missing_itemsets'], $strategy);
                break;
            case 'theme_templates_check':
            case 'theme_templates_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\ThemeTemplate::class, $defaultParams + $params['themes']['theme_templates'] + $params['themes']['theme_templates_warn']);
                break;
            case 'install_check':
                // TODO Make theses tasks available separately, in particular for a whole check.
                $this->checkInstall();
                break;
            case 'settings_environment_check':
            case 'settings_environment_fix':
                $this->checkSettingsEnvironment(
                    $process === 'settings_environment_fix',
                    !empty($params['system']['settings_environment']['include_empty'])
                );
                break;
            case 'security_check':
                // The anonymous api probe is the only slow part of the audit
                // (two http requests to this install), so it is optional.
                $this->checkSecurity(!empty($params['security']['audit']['probe_api']));
                break;
            case 'security_htaccess_fix':
                $this->fixSecurityHtaccess();
                break;
            case 'cache_check':
            case 'cache_fix':
                $this->checkCache($params['system']['cache'], $process === 'cache_fix');
                break;
            case 'mail_check':
                $this->checkMail($params['system']['mail'] ?? []);
                break;
            case 'phpcli_check':
                $this->checkPhpCli();
                break;
            case 'phpcli_test_job':
                $this->testPhpCliJob();
                break;
            case 'db_fulltext_index':
                $job = $dispatcher->dispatch(\Omeka\Job\IndexFulltextSearch::class);
                break;
            case 'db_orphan_tables_check':
                $this->checkOrphanTables();
                break;
            default:
                $eventManager = $this->getEventManager();
                $args = $eventManager->prepareArgs([
                    'process' => $process,
                    'params' => $params,
                    'job' => null,
                    'args' => [],
                ]);
                $eventManager->trigger('easyadmin.job', $this, $args);
                $jobClass = $args['job'];
                if ($jobClass) {
                    $job = $dispatcher->dispatch($jobClass, $args['args']);
                } else {
                    $this->messenger()->addError(new PsrMessage(
                        'Unknown process "{process}"', // @translate
                        ['process' => $process]
                    ));
                }
                break;
        }

        if ($job) {
            $urlPlugin = $this->url();
            $message = new PsrMessage(
                $isQuick
                    // A quick process is already finished when the page is
                    // rendered, so its messages are displayed above.
                    ? 'Process done (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).' // @translate
                    : 'Processing checks in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
                [
                    'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                    'job_id' => $job->getId(),
                    'link_end' => '</a>',
                    'link_log' => class_exists('Log\Module', false)
                        ? sprintf('<a href="%1$s">', htmlspecialchars($urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                        : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
                ]
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
        }

        // Reset the form after a submission.
        $form = $this->getForm(\EasyAdmin\Form\CheckAndFixForm::class);
        return $view
            ->setVariable('form', $form);
    }

    protected function checkCache(array $options, bool $fix): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger  */
        $messenger = $this->messenger();

        if (empty($options['type'])) {
            $messenger->addWarning('No type of cache selected.'); // @translate
            return;
        }

        if (in_array('doctrine', $options['type'])) {
            // Get the entity manager without factory.
            /** @var \Doctrine\ORM\EntityManager $entityManager */
            $services = $this->getEvent()->getApplication()->getServiceManager();
            $entityManager = $services->get('Omeka\EntityManager');
            $cache = $entityManager->getConfiguration()->getMetadataCache();
            if ($cache) {
                if (!$fix) {
                    $messenger->addNotice('The application cache of Symfony Doctrine is available.'); // @translate
                } else {
                    $result = false;
                    if (method_exists($cache, 'clear')) {
                        $result = $cache->clear();
                    } elseif (method_exists($cache, 'deleteAll')) {
                        $result = $cache->deleteAll();
                    }
                    if ($result) {
                        $messenger->addSuccess('The application cache of Symfony Doctrine was reset.'); // @translate
                    } else {
                        $messenger->addWarning('The application cache of Symfony Doctrine cannot be reset.'); // @translate
                    }
                }
            } else {
                $messenger->addNotice('The application cache of Symfony Doctrine is disabled.'); // @translate
            }
        }

        if (in_array('code', $options['type'])) {
            $hasCache = function_exists('opcache_reset');
            if ($hasCache) {
                $result = @opcache_get_status(false);
                if (!$result) {
                    $messenger->addWarning('An issue occurred when checking status of "opcache" or the status is not enabled.'); // @translate
                } else {
                    /*
                    $resultConfig = @opcache_get_configuration();
                    $json = json_encode($resultConfig, 448);
                    $msg = new PsrMessage(nl2br(htmlspecialchars($json === false ? '{}' : $json, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                    */
                    $json = json_encode($result, 448);
                    $msg = new PsrMessage(nl2br(htmlspecialchars($json === false ? '{}' : $json, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                }
                if ($fix) {
                    $result = opcache_reset();
                    if ($result) {
                        $messenger->addSuccess('The cache "opcache" was reset.'); // @translate
                    } else {
                        $messenger->addWarning('The cache "opcache" is disabled.'); // @translate
                    }
                }
            } else {
                $messenger->addWarning('The php extension "opcache" is not available.'); // @translate
            }
        }

        if (in_array('data', $options['type'])) {
            $hasCache = function_exists('apcu_clear_cache');
            if ($hasCache) {
                $result = @apcu_cache_info(true);
                if (!$result) {
                    $messenger->addWarning('An issue occurred when checking status of "apcu" or the status is disabled.'); // @translate
                } else {
                    $json = json_encode($result, 448);
                    $msg = new PsrMessage(nl2br(htmlspecialchars($json === false ? '{}' : $json, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                }
                if ($fix) {
                    apcu_clear_cache();
                    $messenger->addSuccess('The cache "apcu" was reset.'); // @translate
                }
            } else {
                $messenger->addWarning('The php extension "apcu" is not available.'); // @translate
            }
        }

        if (in_array('path', $options['type'])
            && $fix
        ) {
            @clearstatcache(true);
            $messenger->addSuccess('The cache of real paths was reset.'); // @translate
        }
    }

    protected function checkInstall(): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger  */
        $messenger = $this->messenger();

        // Get the installer without factory.
        /** @var \Omeka\Installation\Installer $installer */
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $installer = $services->get('Omeka\Installer');
        $result = $installer->preInstall();
        if ($result) {
            $messenger->addSuccess('Install tasks were run without errors.'); // @translate
        } else {
            $messenger->addErrors($installer->getErrors());
        }

        if (!extension_loaded('intl')) {
            $messenger->addWarning(
                'The php extension "intl" is not available. It is recommended to install it to translate dates.' // @translate
            );
        }
    }

    /**
     * List the database tables that neither the core nor an active module
     * declares. They are split in three groups: tables of an installed but
     * inactive module (kept, used again once the module is reactivated), tables
     * likely useless (module present on disk but not installed, or a removed
     * feature: temporary or test tables, old triplestore, reference_metadata,
     * the term table replaced by concept…), and other unexplained tables. The
     * task only lists them and never drops anything.
     *
     * A table is legitimate when it is declared by the core schema, mapped by
     * an active entity, or declared by an active module (entity,
     * "data/install/schema.sql" or a "CREATE TABLE" in its "Module.php"). Some
     * tables are created by a third party library of a module (e.g. the
     * triplestore of the module Sparql through semsol/arc2): they are matched
     * by a curated name pattern so they follow the state of their module. An
     * inactive module is still installed, unlike a module present on disk but
     * absent from the "module" table.
     */
    protected function checkOrphanTables(): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();
        $services = $this->getEvent()->getApplication()->getServiceManager();
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $services->get('Omeka\EntityManager');

        // Core tables (including the non-entity ones: migration, session…).
        $legit = $this->orphanParseCreateTables(OMEKA_PATH . '/application/data/install/schema.sql');
        $legit['migration'] = true;

        // Tables mapped by the active entities (core and active modules),
        // including the many-to-many join tables of their associations.
        try {
            foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $meta) {
                if ($meta->isMappedSuperclass) {
                    continue;
                }
                $table = $meta->getTableName();
                if ($table) {
                    $legit[strtolower($table)] = true;
                }
                foreach ($meta->associationMappings as $mapping) {
                    if (!empty($mapping['joinTable']['name'])) {
                        $legit[strtolower($mapping['joinTable']['name'])] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $messenger->addError(new PsrMessage(
                'The mapping of the entities could not be read ({message}); the list of useless tables cannot be built.', // @translate
                ['message' => $e->getMessage()]
            ));
            return;
        }

        $tables = $connection->executeQuery('SHOW TABLES')->fetchFirstColumn();

        // Approximate row counts for the whole schema in a single query.
        $counts = [];
        foreach ($connection->executeQuery(
            'SELECT table_name AS n, table_rows AS r FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchAllAssociative() as $row) {
            $counts[strtolower((string) $row['n'])] = (int) $row['r'];
        }

        // Modules present in the "module" table are installed (active or not);
        // the state is matched case-insensitively with the directory name.
        $states = [];
        foreach ($connection->executeQuery('SELECT id, is_active FROM module')->fetchAllAssociative() as $row) {
            $states[strtolower($row['id'])] = (bool) $row['is_active'];
        }

        // Tables created by a third party library of a module, by name pattern.
        $libraryPatterns = [
            // Triplestore of the module Sparql, created by semsol/arc2.
            'sparql' => '/^triplestore_/i',
        ];

        // Owner of a table declared by a non-active module: name and whether it
        // is still installed (inactive) or only present on disk (not
        // installed).
        $owner = [];
        foreach ([OMEKA_PATH . '/modules', OMEKA_PATH . '/composer-addons/modules'] as $modulesPath) {
            foreach (glob($modulesPath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                if (!is_file($dir . '/Module.php')) {
                    continue;
                }
                $key = strtolower($name);
                $active = $states[$key] ?? false;
                $installed = array_key_exists($key, $states);
                $declared = $this->orphanScanModuleTables($dir);
                if (isset($libraryPatterns[$key])) {
                    foreach ($tables as $table) {
                        if (preg_match($libraryPatterns[$key], $table)) {
                            $declared[strtolower($table)] = true;
                        }
                    }
                }
                foreach ($declared as $table => $_) {
                    if ($active) {
                        $legit[$table] = true;
                    } elseif (!isset($owner[$table])) {
                        $owner[$table] = ['module' => $name, 'installed' => $installed];
                    }
                }
            }
        }

        // A candidate is very likely useless when its name is a temporary or
        // test table, or matches a removed or replaced feature.
        $removedPattern = '/^(_|reference_metadata$|terms?$|triplestore)/i';

        $inactive = [];
        $useless = [];
        $review = [];
        foreach ($tables as $table) {
            $key = strtolower($table);
            if (isset($legit[$key])) {
                continue;
            }
            $rows = $counts[$key] ?? 0;
            if (isset($owner[$key])) {
                if ($owner[$key]['installed']) {
                    $inactive[] = sprintf('%s (~%d rows) — module %s', $table, $rows, $owner[$key]['module']);
                } else {
                    $useless[] = sprintf('%s (~%d rows) — module %s (present but not installed)', $table, $rows, $owner[$key]['module']);
                }
            } elseif (preg_match($removedPattern, $table)) {
                $useless[] = sprintf('%s (~%d rows) — removed feature', $table, $rows);
            } else {
                $review[] = sprintf('%s (~%d rows)', $table, $rows);
            }
        }

        if (!$inactive && !$useless && !$review) {
            $messenger->addSuccess('No useless table found: every table is declared by the core or an installed module.'); // @translate
            return;
        }

        if ($inactive) {
            sort($inactive);
            $message = new PsrMessage(
                "Tables of installed but inactive modules (kept, they are used again once the module is reactivated):\n{list}", // @translate
                ['list' => '<br/>' . implode('<br/>', array_map('htmlspecialchars', $inactive))]
            );
            $message->setEscapeHtml(false);
            $messenger->addNotice($message);
        }
        if ($useless) {
            sort($useless);
            $message = new PsrMessage(
                "Tables likely useless (module uninstalled or removed feature):\n{list}", // @translate
                ['list' => '<br/>' . implode('<br/>', array_map('htmlspecialchars', $useless))]
            );
            $message->setEscapeHtml(false);
            $messenger->addWarning($message);
        }
        if ($review) {
            sort($review);
            $message = new PsrMessage(
                "Tables declared by no installed module (review before dropping; they may hold data):\n{list}", // @translate
                ['list' => '<br/>' . implode('<br/>', array_map('htmlspecialchars', $review))]
            );
            $message->setEscapeHtml(false);
            $messenger->addWarning($message);
        }
        $messenger->addNotice('This task never drops any table: verify each one before removing it.'); // @translate
    }

    /**
     * Table names declared by a module: entities (src/Entity), install schema
     * ("data/install/schema.sql") and "CREATE TABLE" statements of Module.php.
     *
     * @return array<string, true>
     */
    protected function orphanScanModuleTables(string $dir): array
    {
        return $this->orphanParseEntityTables($dir . '/src/Entity')
            + $this->orphanParseCreateTables($dir . '/data/install/schema.sql')
            + $this->orphanParseCreateTables($dir . '/Module.php');
    }

    /**
     * Table names of the "CREATE TABLE" statements of a sql or php file.
     *
     * @return array<string, true>
     */
    protected function orphanParseCreateTables(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $tables = [];
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\']?([a-z0-9_]+)[`"\']?/i', (string) file_get_contents($file), $matches)) {
            foreach ($matches[1] as $table) {
                $tables[strtolower($table)] = true;
            }
        }
        return $tables;
    }

    /**
     * Table names of the Doctrine entities of a directory: the explicit table
     * name of the mapping, else the underscored class name (the naming strategy
     * of Omeka), so inactive modules (not in the metadata) are covered.
     *
     * @return array<string, true>
     */
    protected function orphanParseEntityTables(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $tables = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $content = (string) file_get_contents($file);
            if (preg_match('/(?:#\[\s*ORM\\\\Table\s*\(\s*name\s*:\s*|@(?:ORM\\\\)?Table\s*\(\s*name\s*=\s*)[\'"]([a-z0-9_]+)[\'"]/i', $content, $m)) {
                $tables[strtolower($m[1])] = true;
            } elseif (preg_match('/\bclass\s+([A-Za-z0-9_]+)/', $content, $m)) {
                $tables[strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $m[1]))] = true;
            }
        }
        return $tables;
    }

    /**
     * List (and optionally adapt) the settings that depend on the server, so
     * they can be reviewed after a copy of the database (prod to test/dev).
     *
     * Only settings whose values are environment-specific are reported: the
     * installation title, urls, hosts, paths, emails, api keys and tokens. The
     * detection is based on the name of the setting, so it covers modules
     * without any dependency on them. Secret values (keys, tokens, passwords)
     * are redacted. The optional fix only prefixes the installation title with
     * « TEST »; all other values require a new server-specific value and are
     * listed for a manual review.
     *
     * Empty settings are listed only when $includeEmpty is set, and some known
     * settings that match a pattern but never depend on the server (standard
     * rights uris, durations, static route paths…) are always excluded.
     */
    protected function checkSettingsEnvironment(bool $fix, bool $includeEmpty): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();

        $services = $this->getEvent()->getApplication()->getServiceManager();
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // Settings matching a pattern but never depending on the server: they
        // are standard rights uris, durations wrongly matched as "token", or
        // static route paths. They are always excluded from the report.
        $excluded = [
            'iiifserver_manifest_rights_uri',
            'iiifserver_manifest_rights_url',
            'imageserver_info_rights_uri',
            'imageserver_info_rights_url',
            'botchallenge_exception_paths',
            'oaipmhrepository_token_expiration_time',
            'aigenerator_max_tokens',
            'contribute_token_duration',
            'guest_forgot_password_html_after',
            'guest_forgot_password_html_before',
            'guest_forgot_password_use_link_in_dialog_login',
        ];

        // Category of a setting, or null when it is not environment-specific.
        // Order matters: secrets are checked before emails/urls/paths so a key
        // like "credential_key_path" is redacted instead of shown as a path.
        $categorize = function (string $key) use ($excluded): ?string {
            if (in_array($key, $excluded, true)) {
                return null;
            }
            if ($key === 'installation_title') {
                return 'title';
            }
            if (preg_match('/(api_?key|_token|_secret|password|_credential)/', $key)) {
                return 'secret';
            }
            if (preg_match('/(_email|_recipient|_sender|reply_to)/', $key)) {
                return 'email';
            }
            if (preg_match('/(base_uri|_uri|_url|_host|_hostname|_endpoint)/', $key)) {
                return 'url';
            }
            if (preg_match('/(_path|_dir|_directory)/', $key)) {
                return 'path';
            }
            return null;
        };

        $labels = [
            'title' => 'Installation title', // @translate
            'url' => 'Urls, hosts and endpoints', // @translate
            'path' => 'Paths and directories', // @translate
            'email' => 'Emails', // @translate
            'secret' => 'Api keys, tokens and secrets (redacted)', // @translate
        ];

        $isEmpty = fn ($value): bool => $value === null || $value === '' || $value === [];

        // The category is guessed from the name, so filter out false positives
        // (option tokens, flags, email templates…) by the shape of the value.
        // The installation title is always shown; empty values only when the
        // option is set, as they may need a server-specific value.
        $relevant = function (string $category, $value) use ($isEmpty, $includeEmpty): bool {
            if ($category === 'title') {
                return true;
            }
            if ($isEmpty($value)) {
                return $includeEmpty;
            }
            if ($category === 'secret') {
                return true;
            }
            $flat = is_array($value)
                ? implode(' ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value))
                : (is_scalar($value) ? (string) $value : '');
            switch ($category) {
                case 'url': return str_contains($flat, '://') || str_starts_with($flat, '//');
                case 'path': return str_contains($flat, '/');
                case 'email': return str_contains($flat, '@');
                default: return true;
            }
        };

        // Format a decoded value for display, redacting secrets.
        $format = function ($value, bool $secret) use ($isEmpty): string {
            if ($isEmpty($value)) {
                return '(empty)';
            }
            if ($secret) {
                return '••• (defined)';
            }
            if (is_scalar($value)) {
                return (string) $value;
            }
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };

        $grouped = array_fill_keys(array_keys($labels), []);

        // The urls pointing to another server than the current one are the
        // most likely to be wrong after a copy of the database.
        $currentHost = strtolower((string) parse_url($this->viewHelpers()->get('ServerUrl')->__invoke(), PHP_URL_HOST));
        $otherHosts = [];

        $collect = function (string $key, $rawValue, string $prefix) use ($categorize, $relevant, $format, $currentHost, &$grouped, &$otherHosts): void {
            $category = $categorize($key);
            if (!$category) {
                return;
            }
            $value = is_string($rawValue) ? json_decode($rawValue, true) : $rawValue;
            if (!$relevant($category, $value)) {
                return;
            }
            $line = sprintf('%s%s = %s', $prefix, $key, $format($value, $category === 'secret'));
            $grouped[$category][] = $line;
            if ($category === 'url' && $currentHost !== '') {
                $flat = is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value;
                preg_match_all('~(?:https?:)?//([a-z0-9.-]+)~i', $flat, $matches);
                foreach (array_unique(array_map('strtolower', $matches[1])) as $host) {
                    if ($host !== $currentHost) {
                        $otherHosts[] = $line;
                        break;
                    }
                }
            }
        };

        $settings = $connection->executeQuery('SELECT id, value FROM setting ORDER BY id')->fetchAllAssociative();
        foreach ($settings as $row) {
            $collect($row['id'], $row['value'], '');
        }

        $siteSettings = $connection->executeQuery('SELECT id, site_id, value FROM site_setting ORDER BY site_id, id')->fetchAllAssociative();
        foreach ($siteSettings as $row) {
            $collect($row['id'], $row['value'], sprintf('[site #%s] ', $row['site_id']));
        }

        $blocks = [];
        foreach ($labels as $category => $label) {
            if (!$grouped[$category]) {
                continue;
            }
            $blocks[] = '<strong>' . $this->translator()->translate($label) . '</strong><br/>'
                . implode('<br/>', array_map('htmlspecialchars', $grouped[$category]));
        }

        if (!$blocks) {
            $messenger->addNotice('No environment-specific setting found.'); // @translate
        } else {
            $message = new PsrMessage(
                "Settings to review after a copy of the database:\n{list}", // @translate
                ['list' => '<br/><br/>' . implode('<br/><br/>', $blocks)]
            );
            $message->setEscapeHtml(false);
            $messenger->addSuccess($message);
        }

        if ($otherHosts) {
            $message = new PsrMessage(
                "Settings with an url of another server than this one ({host}), to check first:\n{list}", // @translate
                ['host' => $currentHost, 'list' => '<br/>' . implode('<br/>', array_map('htmlspecialchars', $otherHosts))]
            );
            $message->setEscapeHtml(false);
            $messenger->addWarning($message);
        }

        $this->checkSettingsImageServer();

        // Configuration outside the settings tables, not detectable here.
        $messenger->addNotice(new PsrMessage(
            'Also review configurations stored outside the settings: search engines (SearchSolr cores/nodes, url and host), file paths and mailer in "config/local.config.php", and any external service of the modules.' // @translate
        ));

        if (!$fix) {
            return;
        }

        /** @var \Omeka\Settings\Settings $mainSettings */
        $mainSettings = $services->get('Omeka\Settings');
        $title = (string) $mainSettings->get('installation_title', '');
        if (mb_strpos($title, 'TEST') === 0) {
            $messenger->addNotice(new PsrMessage(
                'The installation title already starts with « TEST »: {title}', // @translate
                ['title' => $title]
            ));
        } else {
            $newTitle = trim('TEST ' . $title);
            $mainSettings->set('installation_title', $newTitle);
            $messenger->addSuccess(new PsrMessage(
                'The installation title is now: {title}', // @translate
                ['title' => $newTitle]
            ));
        }
    }

    /**
     * Check the mode of tiling of the module Image Server when an external image
     * server (Cantaloupe, IIPImage…) answers to the iiif urls.
     *
     * After a copy of the database, the mode may be the one of another server:
     * the module creates local tiles for each new media, while the external
     * image server serves the images and ignores them.
     */
    protected function checkSettingsImageServer(): void
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $module = $services->get('Omeka\ModuleManager')->getModule('ImageServer');
        if (!$module || $module->getState() !== \Omeka\Module\Manager::STATE_ACTIVE) {
            return;
        }

        $settings = $services->get('Omeka\Settings');
        $tileMode = $settings->get('imageserver_tile_mode', 'auto');
        if ($tileMode !== 'auto') {
            return;
        }

        $server = $settings->get('iiifserver_media_api_url') ?: $this->probeExternalImageServer();
        if (!$server) {
            return;
        }

        $this->messenger()->addWarning(new PsrMessage(
            'An external image server answers to the iiif urls ({server}), but the module Image Server creates local tiles for each new media (option "Tile processing mode" = "auto"). Set it to "external" if the external server serves all the images.', // @translate
            ['server' => $server]
        ));
    }

    /**
     * Get the name of the external image server answering to the iiif urls.
     *
     * The self request reads the headers of the response to the info.json of a
     * missing media: Omeka answers with Apache/php, an external server with its
     * own name.
     */
    protected function probeExternalImageServer(): ?string
    {
        $url = $this->viewHelpers()->get('ServerUrl')->__invoke()
            . $this->viewHelpers()->get('BasePath')->__invoke()
            . '/iiif/3/0/info.json';
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true],
        ]);
        @file_get_contents($url, false, $context);
        $headers = implode("\n", $http_response_header ?? []);
        if (stripos($headers, 'Cantaloupe') !== false || preg_match('/^Server:\s*Jetty/im', $headers)) {
            return 'Cantaloupe';
        }
        if (stripos($headers, 'IIPImage') !== false || stripos($headers, 'iipsrv') !== false) {
            return 'IIPImage';
        }
        return null;
    }

    /**
     * Audit the security and privacy of the installation: file protection, user
     * data leaks, private data leaks and dangerous settings. Read only: the
     * report is displayed as messages, nothing is modified.
     */
    protected function checkSecurity(bool $probeApi = true): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();
        $services = $this->getEvent()->getApplication()->getServiceManager();
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        $this->securityCheckFileProtection($messenger, $services);
        $this->securityCheckPrivateData($messenger, $connection, $services);
        $this->securityCheckUserData($messenger, $connection);
        $this->securityCheckDangerousSettings($messenger, $services);
        if ($probeApi) {
            $this->securityProbeAnonymousApi($messenger, $connection);
        }
    }

    /**
     * Add a ".htaccess" that denies web access to the sensitive directories of
     * the files directory (backups, imports, exports, logs, contributions, user
     * data…). Public media directories are never touched, and an existing
     * ".htaccess" is never overwritten. The owning module of each directory is
     * indicated when known.
     */
    protected function fixSecurityHtaccess(): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();
        $services = $this->getEvent()->getApplication()->getServiceManager();

        $protector = $this->securityDirectoryProtector($services);
        if (!is_dir($protector->basePath())) {
            $messenger->addError(new PsrMessage(
                'The files directory was not found: {path}', // @translate
                ['path' => $protector->basePath()]
            ));
            return;
        }

        $result = $protector->protectSensitiveDirectories();

        if ($result['created']) {
            $messenger->addSuccess(new PsrMessage(
                'A ".htaccess" was added to {count} directory(ies): {list}', // @translate
                ['count' => count($result['created']), 'list' => $this->securityFormatOwners($result['created'])]
            ));
        }
        if ($result['existing']) {
            $messenger->addNotice(new PsrMessage(
                '{count} directory(ies) already have a ".htaccess" (not modified): {list}', // @translate
                ['count' => count($result['existing']), 'list' => $this->securityFormatOwners($result['existing'])]
            ));
        }
        if ($result['failed']) {
            $messenger->addError(new PsrMessage(
                'A ".htaccess" could not be written in {count} directory(ies) (check permissions): {list}', // @translate
                ['count' => count($result['failed']), 'list' => $this->securityFormatOwners($result['failed'])]
            ));
        }
        if (!$result['created'] && !$result['existing'] && !$result['failed']) {
            $messenger->addNotice('No sensitive directory to protect was found.'); // @translate
        }
    }

    protected function securityCheckFileProtection($messenger, $services): void
    {
        $protector = $this->securityDirectoryProtector($services);
        if (!is_dir($protector->basePath())) {
            $messenger->addWarning(new PsrMessage(
                'The files directory was not found: {path}', // @translate
                ['path' => $protector->basePath()]
            ));
            return;
        }

        $audit = $protector->audit();

        if ($audit['php']) {
            sort($audit['php']);
            $messenger->addError(new PsrMessage(
                'The files directory contains executable php file(s), which is a code execution risk: {list}. Remove them and forbid php execution under "files/".', // @translate
                ['list' => implode(', ', $audit['php'])]
            ));
        }
        if ($audit['sensitive']) {
            $messenger->addWarning(new PsrMessage(
                'Sensitive directories without a ".htaccess" are publicly accessible (backups, imports, exports, logs…): {list}. Use the fix to protect them.', // @translate
                ['list' => $this->securityFormatOwners($audit['sensitive'])]
            ));
        }
        if ($audit['unknown']) {
            sort($audit['unknown']);
            $messenger->addNotice(new PsrMessage(
                'Other directories of "files/" have no ".htaccess"; review whether they should be public (no ".htaccess" is added automatically, as they may serve public files): {list}', // @translate
                ['list' => implode(', ', $audit['unknown'])]
            ));
        }
        if (!$audit['php'] && !$audit['sensitive']) {
            $messenger->addSuccess('File protection: no sensitive directory left publicly accessible.'); // @translate
        }
    }

    /**
     * The directory protector for the files directory, with the map of the
     * directories owned by a module (from the settings storing a path).
     */
    protected function securityDirectoryProtector($services): \EasyAdmin\Stdlib\FileDirectoryProtector
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?? null;
        $basePath = $basePath ?: (OMEKA_PATH . '/files');
        $owners = \EasyAdmin\Stdlib\FileDirectoryProtector::buildOwnerMap($services->get('Omeka\Connection'));
        return new \EasyAdmin\Stdlib\FileDirectoryProtector($basePath, $owners, $services->get('Common\DirectoryManager'));
    }

    /**
     * Format a map of directory name => owner module as "name (module X)".
     */
    protected function securityFormatOwners(array $ownersMap): string
    {
        $lines = [];
        foreach ($ownersMap as $name => $owner) {
            $lines[] = $owner === null
                ? $name
                : sprintf('%s (module %s)', $name, $owner);
        }
        sort($lines);
        return implode(', ', $lines);
    }

    protected function securityCheckPrivateData($messenger, $connection, $services): void
    {
        $privateItems = (int) $connection->executeQuery('SELECT COUNT(*) FROM item i JOIN resource r ON r.id = i.id WHERE r.is_public = 0')->fetchOne();
        $privateMedia = (int) $connection->executeQuery('SELECT COUNT(*) FROM media m JOIN resource r ON r.id = m.id WHERE r.is_public = 0')->fetchOne();
        $privateValues = (int) $connection->executeQuery('SELECT COUNT(*) FROM value WHERE is_public = 0')->fetchOne();

        $messenger->addNotice(new PsrMessage(
            'Private data: {items} private items, {media} private media, {values} private values.', // @translate
            ['items' => $privateItems, 'media' => $privateMedia, 'values' => $privateValues]
        ));

        // A public media whose item is private is reachable while its item is
        // not: its file and metadata leak out of the private item.
        $publicMediaPrivateItem = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM media m'
            . ' JOIN resource mr ON mr.id = m.id'
            . ' JOIN resource ir ON ir.id = m.item_id'
            . ' WHERE mr.is_public = 1 AND ir.is_public = 0'
        )->fetchOne();
        if ($publicMediaPrivateItem) {
            $messenger->addWarning(new PsrMessage(
                '{count} public media belong to a private item: their file and metadata leak. Set them private or make the item public.', // @translate
                ['count' => $publicMediaPrivateItem]
            ));
        }

        // The original files of private media stay in the public files
        // directory: they are directly downloadable unless a module (Access)
        // routes them through an access control.
        $privateMediaWithFile = (int) $connection->executeQuery('SELECT COUNT(*) FROM media m JOIN resource r ON r.id = m.id WHERE r.is_public = 0 AND m.has_original = 1')->fetchOne();
        if ($privateMediaWithFile) {
            /** @var \Omeka\Module\Manager $moduleManager */
            $moduleManager = $services->get('Omeka\ModuleManager');
            $access = $moduleManager->getModule('Access');
            $accessActive = $access && $access->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
            if ($accessActive) {
                $messenger->addNotice(new PsrMessage(
                    '{count} private media have an original file. The module Access is active, so their direct download should be controlled; check its configuration.', // @translate
                    ['count' => $privateMediaWithFile]
                ));
            } else {
                $messenger->addWarning(new PsrMessage(
                    '{count} private media have an original file directly downloadable from "files/original/": no module protects private files. Install and configure the module Access.', // @translate
                    ['count' => $privateMediaWithFile]
                ));
            }
        }
    }

    protected function securityCheckUserData($messenger, $connection): void
    {
        $placeholder = (int) $connection->executeQuery("SELECT COUNT(*) FROM user WHERE email LIKE '%@example.%' OR email LIKE '%@example.org'")->fetchOne();
        if ($placeholder) {
            $messenger->addWarning(new PsrMessage(
                '{count} user(s) have a placeholder email (@example.*): remove test accounts or set real emails.', // @translate
                ['count' => $placeholder]
            ));
        }

        $admins = (int) $connection->executeQuery("SELECT COUNT(*) FROM user WHERE role IN ('global_admin', 'site_admin') AND is_active = 1")->fetchOne();
        $messenger->addNotice(new PsrMessage(
            'User accounts: {admins} active administrators. Apply the least privilege principle and review them.', // @translate
            ['admins' => $admins]
        ));

        $apiKeys = (int) $connection->executeQuery('SELECT COUNT(*) FROM api_key')->fetchOne();
        if ($apiKeys) {
            $messenger->addWarning(new PsrMessage(
                '{count} api key(s) are registered: they are long lived credentials, revoke the unused ones.', // @translate
                ['count' => $apiKeys]
            ));
        }
    }

    protected function securityCheckDangerousSettings($messenger, $services): void
    {
        /** @var \Omeka\Settings\Settings $settings */
        $settings = $services->get('Omeka\Settings');
        $config = $services->get('Config');

        $dangerous = [];
        if ($settings->get('easyadmin_disable_csrf')) {
            $dangerous[] = 'easyadmin_disable_csrf';
        }
        if ($settings->get('easyadmin_display_exception')) {
            $dangerous[] = 'easyadmin_display_exception';
        }
        if ($settings->get('easyadmin_local_path_any') || $settings->get('easyadmin_local_path_any_files')) {
            $dangerous[] = 'easyadmin_local_path_any';
        }
        if ($settings->get('disable_file_validation')) {
            $dangerous[] = 'disable_file_validation';
        }
        if (!empty($config['view_manager']['display_exceptions'])) {
            $dangerous[] = 'view_manager.display_exceptions';
        }
        if (!empty($config['view_manager']['display_not_found_reason'])) {
            $dangerous[] = 'view_manager.display_not_found_reason';
        }

        if ($dangerous) {
            $messenger->addError(new PsrMessage(
                'Dangerous settings are enabled and should be disabled in production: {list}', // @translate
                ['list' => implode(', ', $dangerous)]
            ));
        } else {
            $messenger->addSuccess('Settings: no dangerous option enabled.'); // @translate
        }

        $cookieOptions = $config['session']['config']['options'] ?? [];
        $cookieWarnings = [];
        if (empty($cookieOptions['cookie_httponly'])) {
            $cookieWarnings[] = 'cookie_httponly';
        }
        if (empty($cookieOptions['cookie_secure'])) {
            $cookieWarnings[] = 'cookie_secure';
        }
        if ($cookieWarnings) {
            $messenger->addNotice(new PsrMessage(
                'Session cookie hardening is not enforced in the configuration ({list}); make sure it is set at the server level (https only, http only).', // @translate
                ['list' => implode(', ', $cookieWarnings)]
            ));
        }
    }

    /**
     * Best effort probe: query the api anonymously to check that users and
     * private resources are not readable without authentication. It is a
     * separate http request, so it is not authenticated by the current session.
     */
    protected function securityProbeAnonymousApi($messenger, $connection): void
    {
        try {
            $request = $this->getRequest();
            $uri = $request->getUri();
            $port = $uri->getPort();
            $base = $uri->getScheme() . '://' . $uri->getHost()
                . ($port && !in_array($port, [80, 443], true) ? ':' . $port : '');
            $apiBase = $base . rtrim($request->getBasePath(), '/') . '/api';

            $client = new \Laminas\Http\Client(null, ['timeout' => 5, 'sslverifypeer' => false]);

            // Anonymous access to the list of users must not expose emails.
            $response = $client->setUri($apiBase . '/users')->setMethod('GET')->send();
            if ($response->isSuccess()) {
                $users = json_decode($response->getBody(), true);
                $leak = is_array($users) && array_filter($users, fn ($u) => !empty($u['o:email']));
                if ($leak) {
                    $messenger->addError('Privacy leak: the api exposes user emails to anonymous requests (/api/users).'); // @translate
                } else {
                    $messenger->addSuccess('Api probe: anonymous access to users does not expose emails.'); // @translate
                }
            } else {
                $messenger->addSuccess('Api probe: anonymous access to users is forbidden.'); // @translate
            }

            // Anonymous access to a private item must be forbidden.
            $privateItemId = $connection->executeQuery('SELECT i.id FROM item i JOIN resource r ON r.id = i.id WHERE r.is_public = 0 LIMIT 1')->fetchOne();
            if ($privateItemId) {
                $response = $client->setUri($apiBase . '/items/' . $privateItemId)->setMethod('GET')->send();
                if ($response->isSuccess()) {
                    $messenger->addError(new PsrMessage(
                        'Privacy leak: the private item #{id} is readable by anonymous requests through the api.', // @translate
                        ['id' => $privateItemId]
                    ));
                } else {
                    $messenger->addSuccess('Api probe: anonymous access to a private item is forbidden.'); // @translate
                }
            }
        } catch (\Throwable $e) {
            $messenger->addNotice(new PsrMessage(
                'The anonymous api probe could not be run ({message}); check the api access manually.', // @translate
                ['message' => $e->getMessage()]
            ));
        }
    }

    /**
     * Check the PHP-CLI used to run the background jobs: the configured path
     * (or the auto-detected one), whether it is valid (the cause of the error
     * "PHP-CLI error: invalid PHP path"), whether its version matches the web
     * server, and whether it has the extensions required by the jobs. It then
     * scans the usual locations and suggests the correct path to configure.
     */
    protected function checkPhpCli(): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();
        $services = $this->getEvent()->getApplication()->getServiceManager();

        $strategyClass = get_class($services->get('Omeka\Job\Dispatcher')->getDispatchStrategy());
        $checker = new \EasyAdmin\Stdlib\JobCliChecker(
            $services->get('Omeka\Cli'),
            $services->get('Config'),
            $strategyClass
        );
        $r = $checker->check();

        $webVersion = $r['web']['version'];
        $webMajorMinor = $r['web']['majorMinor'];

        // Dispatch strategy: synchronous jobs do not use the PHP-CLI at all.
        if ($r['dispatchStrategy'] !== null && stripos($r['dispatchStrategy'], 'Synchronous') !== false) {
            $messenger->addNotice('Jobs use the "Synchronous" strategy: they run in the web process and do not use the PHP-CLI, so this check is only informational.'); // @translate
        }

        // Without proc_open/exec no job can even be started.
        if (!$r['canSpawn']['ok']) {
            $messenger->addError('The web PHP cannot spawn a process ("proc_open" and "exec" are both disabled); no background job can start, whatever the PHP-CLI path. Remove one of them from "disable_functions".'); // @translate
        }

        // Configured or auto-detected path.
        if ($r['configured'] && $r['effective'] === false) {
            $messenger->addError(new PsrMessage(
                'The configured PHP-CLI path is invalid ({reason}): {path}. This is the cause of the error "PHP-CLI error: invalid PHP path"; jobs cannot run.', // @translate
                ['reason' => $this->translate($r['invalidReason'] ?? 'the path is invalid'), 'path' => $r['configured']] // @translate
            ));
        } elseif ($r['auto']) {
            if ($r['effective'] === false) {
                $messenger->addError('No PHP-CLI path is configured and none could be auto-detected ("command -v php" failed); jobs cannot run.'); // @translate
            } else {
                $messenger->addNotice(new PsrMessage(
                    'No PHP-CLI path is configured; Omeka auto-detects "{path}". It is safer to set it explicitly to the binary matching the web version.', // @translate
                    ['path' => $r['effective']]
                ));
            }
        }

        $messenger->addNotice(new PsrMessage(
            'Web PHP version (this page): {version} (SAPI {sapi}).', // @translate
            ['version' => $webVersion, 'sapi' => $r['web']['sapi']]
        ));

        // Effective binary: SAPI, version match, pdo_mysql, extensions parity.
        if (is_string($r['effective']) && $r['effective'] !== '') {
            if ($r['effectiveInfo'] === null) {
                $messenger->addWarning(new PsrMessage(
                    'The PHP binary could not be executed from the web context ({path}); check open_basedir, disabled functions and permissions.', // @translate
                    ['path' => $r['effective']]
                ));
            } else {
                if ($r['sapiIsCli'] === false) {
                    $messenger->addWarning(new PsrMessage(
                        'The configured PHP binary uses the "{sapi}" SAPI, not CLI ({path}); php-cgi and php-fpm binaries emit headers and mishandle arguments (perform-job.php reads them with getopt, empty under cgi, so the job exits and stays "starting"). Prefer a php-cli binary.', // @translate
                        ['sapi' => $r['effectiveInfo']['sapi'], 'path' => $r['effective']]
                    ));
                }
                if ($r['versionMatch']) {
                    $messenger->addSuccess(new PsrMessage(
                        'The PHP-CLI version ({cli}) matches the web version ({web}): {path}.', // @translate
                        ['cli' => $r['effectiveInfo']['version'], 'web' => $webVersion, 'path' => $r['effective']]
                    ));
                } else {
                    $messenger->addError(new PsrMessage(
                        'The PHP-CLI version ({cli}) differs from the web version ({web}); jobs may fail or behave differently (deprecations, missing extensions, serialization). Configure a PHP {major} binary. Current path: {path}.', // @translate
                        ['cli' => $r['effectiveInfo']['version'], 'web' => $webVersion, 'major' => $webMajorMinor, 'path' => $r['effective']]
                    ));
                }
                if ($r['hasPdoMysql'] === false) {
                    $messenger->addError(new PsrMessage(
                        'The PHP binary {path} is missing the extension "pdo_mysql", required by jobs to access the database.', // @translate
                        ['path' => $r['effective']]
                    ));
                }
                if ($r['missingExtensions']) {
                    $messenger->addWarning(new PsrMessage(
                        'The PHP-CLI is missing {count} extension(s) loaded on the web (jobs run the full application, so it should have the same): {list}.', // @translate
                        ['count' => count($r['missingExtensions']), 'list' => implode(', ', $r['missingExtensions'])]
                    ));
                }
            }
        }

        // open_basedir: binaries outside the perimeter are invisible and
        // unusable.
        if ($r['openBasedir']) {
            $messenger->addNotice(new PsrMessage(
                'open_basedir is active on the web ({dirs}); a PHP binary outside this perimeter is neither testable nor usable by jobs. If the correct binary is outside, add its directory to open_basedir.', // @translate
                ['dirs' => implode(', ', $r['openBasedir'])]
            ));
        }

        // Candidates found on the usual locations.
        $lines = [];
        foreach ($r['candidates'] as $candidate) {
            if ($candidate['status'] === 'outside_openbasedir') {
                $lines[] = sprintf('%s — outside open_basedir (add its directory to the perimeter to use it)', $candidate['path']);
            } elseif ($candidate['status'] === 'not_executable') {
                $lines[] = sprintf('%s — not executable in this context', $candidate['path']);
            } else {
                $lines[] = sprintf(
                    '%s — %s (%s)%s%s',
                    $candidate['path'],
                    $candidate['version'],
                    $candidate['sapi'] !== '' ? $candidate['sapi'] : 'unknown',
                    $candidate['pdo'] === false ? ' (no pdo_mysql)' : '',
                    $candidate['ok'] ? ($candidate['cli'] ? ' [recommended]' : ' [usable, cgi]') : ''
                );
            }
        }
        if ($lines) {
            $message = new PsrMessage(
                "Available PHP binaries:\n{list}", // @translate
                ['list' => '<br/>' . implode('<br/>', array_map('htmlspecialchars', $lines))]
            );
            $message->setEscapeHtml(false);
            $messenger->addNotice($message);
        }

        if ($r['recommendedCli'] !== null && $r['recommendedCli'] !== $r['effective']) {
            $messenger->addSuccess(new PsrMessage(
                'Recommended PHP-CLI path (matches the web version {web} and has pdo_mysql): {path}. Set the key ["cli"]["phpcli_path"] to it in config/local.config.php, then reload php-fpm.', // @translate
                ['web' => $webVersion, 'path' => $r['recommendedCli']]
            ));
        } elseif ($r['recommendedCli'] === null && $r['recommendedCgi'] !== null) {
            $messenger->addWarning(new PsrMessage(
                'No php-cli binary was found, only a php-cgi one ({path}); a php-cli binary is strongly preferred for jobs. If you must use it, set ["cli"]["phpcli_path"] to it and verify that jobs actually complete.', // @translate
                ['path' => $r['recommendedCgi']]
            ));
        } elseif ($r['recommendedCli'] === null) {
            $messenger->addWarning(new PsrMessage(
                'No PHP {major} binary with pdo_mysql was found in the usual locations; install one or set the path manually.', // @translate
                ['major' => $webMajorMinor]
            ));
        }
    }

    /**
     * Dispatch a trivial job through the real strategy, to test the whole
     * background pipeline end to end (the only decisive check).
     */
    protected function testPhpCliJob(): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();
        try {
            $job = $this->jobDispatcher()->dispatch(\EasyAdmin\Job\JobCliTest::class);
        } catch (\Throwable $e) {
            $messenger->addError(new PsrMessage(
                'The test job could not be dispatched ({message}). Fix the PHP-CLI configuration (check the audit above).', // @translate
                ['message' => $e->getMessage()]
            ));
            return;
        }
        if (!$job) {
            $messenger->addError('The test job could not be dispatched.'); // @translate
            return;
        }
        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Test job dispatched ({link_job}#{job_id}{link_end}). If it reaches the status "completed", the background pipeline works; if it stays "starting", the PHP-CLI ran but could not bootstrap (version, extensions, database, or getopt under a cgi SAPI).', // @translate
            [
                'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }

    protected function checkMail(array $options): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();

        /** @var \EasyAdmin\Mvc\Controller\Plugin\CheckMailer $checkMailer */
        $checkMailer = $this->checkMailer();

        // Get the services (needed for mailer).
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');

        // Get and display configuration summary.
        $configSummary = $checkMailer->getConfigSummary();
        $configHtml = implode('<br/>', array_map('strval', $configSummary));
        $message = new PsrMessage(
            "Current email configuration:\n{config}", // @translate
            ['config' => '<br/>' . $configHtml]
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);

        // Check dns records if requested.
        $checkDns = !empty($options['check_dns']);
        if ($checkDns) {
            $checkMailer->checkDnsWithMessages($options);
        }

        // Send test email if recipient is provided.
        $recipient = trim($options['recipient'] ?? '');
        if (empty($recipient)) {
            $messenger->addNotice('No recipient email provided. Test email was not sent.'); // @translate
            return;
        }

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $messenger->addError(new PsrMessage(
                'Invalid recipient email address: {email}', // @translate
                ['email' => $recipient]
            ));
            return;
        }

        // Send test email.
        try {
            /** @var \Omeka\Stdlib\Mailer $mailer */
            $mailer = $services->get('Omeka\Mailer');
            $installationTitle = $settings->get('installation_title', 'Omeka S');

            $subject = new PsrMessage(
                '[{title}] Test email', // @translate
                ['title' => $installationTitle]
            );
            $body = new PsrMessage(
                "This is a test email from {title}.\n\nEmail configuration:\n{config}\n\nSent at: {datetime}", // @translate
                ['title' => $installationTitle, 'config' => implode("\n", $configSummary), 'datetime' => date('Y-m-d H:i:s T')]
            );

            $message = $mailer->createMessage();
            $message->addTo($recipient)
                ->setSubject($subject->setTranslator($this->translator()))
                ->setBody($body->setTranslator($this->translator()));

            $mailer->send($message);

            $messenger->addSuccess(new PsrMessage(
                'Test email successfully sent to {email}.', // @translate
                ['email' => $recipient]
            ));
        } catch (\Throwable $e) {
            $messenger->addError(new PsrMessage(
                'Failed to send test email: {error}', // @translate
                ['error' => $e->getMessage()]
            ));

            // Diagnose ssl/tls mismatch from the error message.
            $errorMsg = $e->getMessage();
            $mailConfig = $services->get('Config')['mail'] ?? [];
            $transportOptions = $mailConfig['transport']['options'] ?? [];
            $ssl = $transportOptions['connection_config']['ssl'] ?? null;
            $port = $transportOptions['port'] ?? null;
            if ($ssl && (
                stripos($errorMsg, 'ssl') !== false
                || stripos($errorMsg, 'tls') !== false
                || stripos($errorMsg, 'crypto') !== false
                || stripos($errorMsg, 'connect') !== false
            )) {
                $alternative = $ssl === 'tls' ? 'ssl' : 'tls';
                $altPort = $ssl === 'tls' ? 465 : 587;
                $messenger->addNotice(new PsrMessage(
                    'Hint: Your current security setting is "ssl" => "{current}" (port {port}). A common fix is to try "ssl" => "{alternative}" with port {alt_port}.', // @translate
                    ['current' => $ssl, 'port' => $port ?: '?', 'alternative' => $alternative, 'alt_port' => $altPort]
                ));
            }
        }
    }
}
