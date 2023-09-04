<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbValueAnnotationTemplate extends AbstractCheck
{
    /*
    protected $columns = [
        'value_annotation' => 'Value annotation', // @translate
        'resource' => 'Resource', // @translate
        'template' => 'Template', // @translate
        'property' => 'Property', // @translate
        'fixed' => 'Fixed', // @translate
    ];
    */

    public function perform(): void
    {
        parent::perform();

        if (!$this->isModuleActive('AdvancedResourceTemplate')) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'To update value annotation templates requires the module Advanced Resource Template.' // @translate
            );
        }

        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_value_annotation_template_fix';

        $this->checkDbValueAnnotationTemplate($processFix);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    /**
     * Check the template and classes of value annotations.
     */
    protected function checkDbValueAnnotationTemplate(bool $fix): bool
    {
        // Add the resource template and resource class to value annotations.
        // TODO Use a single query instead of four requests (or use a temp view).

        // Get the default template id for all templates.
        $sql = <<<'SQL'
SELECT
    `resource_template_id` AS rtid,
    REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(
        `data`, '"value_annotations_template":', -1
    ), ',', 1), '}', 1), '"', '') AS vartid
FROM `resource_template_data`
WHERE `data` LIKE '%"value#_annotations#_template":%' ESCAPE "#"
    AND `data` NOT LIKE '%"value#_annotations#_template":""%' ESCAPE "#"
    AND `data` NOT LIKE '%"value#_annotations#_template":"none"%' ESCAPE "#"
;
SQL;
        $rtVaTemplates = $this->connection->executeQuery($sql)->fetchAllKeyValue();

        // Get the specific template id for all property templates.
        $sql = <<<'SQL'
SELECT
    CONCAT(`resource_template_property`.`resource_template_id`, "-", `property_id`),
    REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(
        `data`, '"value_annotations_template":', -1
    ), ',', 1), '}', 1), '"', '') AS vartid
FROM `resource_template_property_data`
JOIN `resource_template_property` ON `resource_template_property`.`id` = `resource_template_property_data`.`resource_template_property_id`
WHERE `data` LIKE '%"value#_annotations#_template":%' ESCAPE "#"
;
SQL;
        $rtpVaTemplates = $this->connection->executeQuery($sql)->fetchAllKeyValue();

        // Get the main class associated with the templates.
        $sql = <<<'SQL'
SELECT `id`, `resource_class_id`
FROM `resource_template`
WHERE `resource_class_id` IS NOT NULL
;
SQL;
        $templateClasses = $this->connection->executeQuery($sql)->fetchAllKeyValue();

        // Set default value annotation template when there is no specific property
        // value annotation template.
        foreach ($rtpVaTemplates as $rtProp => &$rtpVaTemplate) {
            $rtpVaTemplate = $rtpVaTemplate ?: ($rtVaTemplates[strtok($rtProp, '-')] ?? null);
        }
        unset($rtpVaTemplate);

        $rtpVaTemplates = array_filter($rtpVaTemplates);

        if (count($rtpVaTemplates)) {
            $rtVaTemplatesString = '';
            $rtVaClassesString = '';
            $rtVaTemplatesCase = '';
            $rtVaClassesCase = '';
            foreach ($rtpVaTemplates as $rtProp => $rtpVaTemplate) {
                $rtVaTemplatesCase .= is_numeric($rtpVaTemplate)
                    ? sprintf("        WHEN '%s' THEN %s\n", $rtProp, $rtpVaTemplate)
                    : '';
                $rtVaClassesCase .= isset($templateClasses[$rtpVaTemplate])
                    ? sprintf("        WHEN '%s' THEN %s\n", $rtProp, $templateClasses[$rtpVaTemplate])
                    : '';
            }
            if (trim($rtVaTemplatesCase)) {
                $rtVaTemplatesString = '    CASE CONCAT(`resource_main`.`resource_template_id`, "-", `value`.`property_id`)' . "\n        "
                    . $rtVaTemplatesCase
                    . "        ELSE NULL\n    END";
            }
            if (trim($rtVaClassesCase)) {
                $rtVaClassesString = '    CASE CONCAT(`resource_main`.`resource_template_id`, "-", `value`.`property_id`)' . "\n        "
                    . $rtVaClassesCase
                    . "        ELSE NULL\n    END";
            }
        }
        if (empty($rtVaTemplatesString)) {
            $rtVaTemplatesString = 'NULL';
        }
        if (empty($rtVaClassesString)) {
            $rtVaClassesString = 'NULL';
        }

        $sql = <<<SQL
SELECT COUNT(`resource`.`id`)
FROM `resource`
INNER JOIN `value` ON `value`.`value_annotation_id` = `resource`.`id`
LEFT JOIN `resource` AS `resource_main` ON `resource_main`.`id` = `value`.`resource_id`
WHERE `value`.`value_annotation_id` IS NOT NULL
;
SQL;
        $result = $this->connection->executeQuery($sql)->fetchOne();
        $this->logger->notice(
            'There are {total} value annotations that may be updated if a template is set.', // @translate
            ['total' => (int) $result]
        );

        if (!$fix) {
            return true;
        }

        // Do the update.
        $sql = <<<SQL
UPDATE `resource`
INNER JOIN `value` ON `value`.`value_annotation_id` = `resource`.`id`
LEFT JOIN `resource` AS `resource_main` ON `resource_main`.`id` = `value`.`resource_id`
SET
    `resource`.`resource_class_id` = $rtVaClassesString,
    `resource`.`resource_template_id` = $rtVaTemplatesString
WHERE `value`.`value_annotation_id` IS NOT NULL
;
SQL;

        $result = $this->connection->executeStatement($sql);
        $this->logger->notice(
            '{total} value annotations were updated.', // @translate
            ['total' => (int) $result]
        );
        return true;
    }
}
