<?php
namespace EasyInstall\Form;

use Zend\Form\Form;
use EasyInstall\Mvc\Controller\Plugin\Addons;

class UploadForm extends Form
{

    /**
     * List of addons.
     *
     * @var array
     */
    protected $addons = array();

    public function init()
    {
        $this->setAttribute('action', 'easyinstall');

        $addonLabels = [
            'module' => 'Modules', // @translate
            'theme' => 'Themes', // @translate
        ];

        $addons = $this->getAddons();
        $list = $addons();
        foreach($list as $addonType => $addonsForType) {
            if (empty($addonsForType)) {
                continue;
            }
            $valueOptions = [];
            $valueOptions[''] = 'Select Below'; // @translate
            foreach ($addonsForType as $url => $addon) {
                $label = $addon['name'];
                $label .= $addon['version'] ? ' [v' . $addon['version'] . ']' : '[]';
                $label .= $addons->dirExists($addon) ? ' *' : '';
                $valueOptions[$url] = $label;
            }

            $this->add([
                'name' => $addonType,
                'type' => 'select',
                'options' => [
                    'label' => $addonLabels[$addonType],
                    'info'  => '',
                    'empty_option' => 'Select Below', // @translate
                    'value_options' => $valueOptions,
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
     * @return void
     */
    public function setAddons(Addons $addons)
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
