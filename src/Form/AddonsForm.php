<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use EasyAdmin\Mvc\Controller\Plugin\Addons;
use Laminas\Form\Element\Select;
use Laminas\Form\Form;

class AddonsForm extends Form
{
    /**
     * @var \EasyAdmin\Mvc\Controller\Plugin\Addons
     */
    protected $addons;

    public function init(): void
    {
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

    public function setAddons(Addons $addons): self
    {
        $this->addons = $addons;
        return $this;
    }

    public function getAddons(): Addons
    {
        return $this->addons;
    }
}
