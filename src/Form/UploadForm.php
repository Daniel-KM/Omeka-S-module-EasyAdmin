<?php declare(strict_types=1);
namespace EasyInstall\Form;

use EasyInstall\Mvc\Controller\Plugin\Addons;
use Laminas\Form\Element\Select;
use Laminas\Form\Form;

class UploadForm extends Form
{
    /**
     * List of addons.
     *
     * @var array
     */
    protected $addons = [];

    public function init(): void
    {
        $this->setAttribute('action', 'easy-install');

        $addonLabels = [
            'omekamodule' => 'Modules Omeka.org', // @translate
            'omekatheme' => 'Themes Omeka.org', // @translate
            'module' => 'Modules web', // @translate
            'theme' => 'Themes web', // @translate
        ];

        $addons = $this->getAddons();
        $list = $addons();
        foreach ($list as $addonType => $addonsForType) {
            if (empty($addonsForType)) {
                continue;
            }
            $valueOptions = [];
            foreach ($addonsForType as $url => $addon) {
                $label = $addon['name'];
                $label .= $addon['version'] ? ' [v' . $addon['version'] . ']' : '[]';
                $label .= $addons->dirExists($addon) ? ' *' : '';
                $valueOptions[$url] = $label;
            }

            $this->add([
                'name' => $addonType,
                'type' => Select::class,
                'options' => [
                    'label' => $addonLabels[$addonType],
                    'info' => '',
                    'empty_option' => '',
                    'value_options' => $valueOptions,
                ],
                'attributes' => [
                    'id' => $addonType,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select below…', // @translate
                ],
            ]);

            $inputFilter = $this->getInputFilter();
            $inputFilter->add([
                'name' => $addonType,
                'required' => false,
            ]);
        }
    }

    /**
     * @param Addons $addons
     */
    public function setAddons(Addons $addons): void
    {
        $this->addons = $addons;
    }

    /**
     * @return array
     */
    public function getAddons()
    {
        return $this->addons;
    }
}
