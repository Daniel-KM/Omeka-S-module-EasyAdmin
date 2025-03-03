<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'easyadmin_local_path_any_files',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Allow to display any folder inside the folder files', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_local_path_any_files',
                ],
            ])
        ;
    }
}
