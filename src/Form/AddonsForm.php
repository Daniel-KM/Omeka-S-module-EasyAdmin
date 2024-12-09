<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use EasyAdmin\Mvc\Controller\Plugin\Addons;
use Laminas\Form\Element;
use Laminas\Form\Form;

class AddonsForm extends Form
{
    /**
     * @var \EasyAdmin\Mvc\Controller\Plugin\Addons
     */
    protected $addons;

    /**
     * @var array
     */
    protected $selections = [];

    public function init(): void
    {
        $addonLabels = [
            'omekamodule' => 'Modules Omeka.org', // @translate
            'omekatheme' => 'Themes Omeka.org', // @translate
            'module' => 'Modules web', // @translate
            'theme' => 'Themes web', // @translate
        ];

        $list = $this->addons->getLists();
        foreach ($list as $addonType => $addonsForType) {
            if (empty($addonsForType)) {
                continue;
            }
            $valueOptions = [];
            foreach ($addonsForType as $url => $addon) {
                $label = $addon['name'];
                $label .= $addon['version'] ? ' [v' . $addon['version'] . ']' : '[]';
                $label .= $this->addons->dirExists($addon) ? ' *' : '';
                $valueOptions[$url] = $label;
            }

            $this
                ->add([
                    'name' => $addonType,
                    'type' => Element\Select::class,
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
            $inputFilter
                ->add([
                    'name' => $addonType,
                    'required' => false,
                ]);
        }

        if (!empty($this->selections)) {
            $this
                ->add([
                    'name' => 'selection',
                    'type' => Element\Select::class,
                    'options' => [
                        'label' => 'Curated selections of modules and themes', // @translate
                        'empty_option' => '',
                        'value_options' => array_combine($this->selections, $this->selections),
                    ],
                    'attributes' => [
                        'id' => 'selection',
                        'class' => 'chosen-select',
                        'data-placeholder' => 'Select below…', // @translate
                    ],
                ]);
            }

        $this
            ->add([
                'name' => 'reset_cache',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Refresh lists of addons and selections', // @translate
                ],
                'attributes' => [
                    'id' => 'reset_cache',
                ],
            ])
        ;

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'selection',
                'required' => false,
            ]);
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

    public function setSelections(array $selections): self
    {
        $this->selections = $selections;
        return $this;
    }

    public function getSelections(): array
    {
        return $this->selections;
    }
}
