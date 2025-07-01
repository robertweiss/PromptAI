<?php namespace ProcessWire;

class PromptAIConfigForm {
    private Module $promptAI;
    private array $promptMatrix;

    public function __construct() {
        /** @var Module promptAI */
        $this->promptAI = wire('modules')->get('PromptAI');
        $this->promptMatrix = $this->promptAI->parsePromptMatrix($this->promptAI->get('promptMatrix'));
    }

    public function render(): string {
        $form = wire('modules')->get('InputfieldForm');
        $form->attr('id', 'prompt-config-form');
        $form->attr('method', 'post');
        $form->attr('action', './');
        $form->attr('x-data', 'promptConfigForm()');

        $moduleUrl = wire('config')->urls->siteModules.'PromptAI/';
        wire('config')->scripts->add($moduleUrl.'alpine.min.js');
        wire('config')->styles->add($moduleUrl . "styles.css");

        $header = '<h2>'._('Prompt configuration').'</h2>';
        $subheader = '<p>'._('Configure the AI prompts that should be used for the different fields. If the source field is an image field, the target field is interpreted as a custom subfield of the image field (if left empty, the image description is used as the target instead).').'</p>';

        // Container for dynamic fieldsets
        $container = wire('modules')->get('InputfieldMarkup');
        $container->label = '';
        $container->value = $this->renderDynamicFieldsets();
        $form->add($container);

        // Hidden submit field
        $submit = wire('modules')->get('InputfieldHidden');
        $submit->attr('name', 'submit_prompt_config');
        $submit->attr('value', '1');
        $form->add($submit);

        // Save button
        $saveButton = wire('modules')->get('InputfieldSubmit');
        $saveButton->attr('name', 'save');
        $saveButton->attr('value', 'Save Configuration');
        $saveButton->attr('class', 'ui-button ui-widget ui-corner-all ui-button-text-only pw-head-button ui-state-default');
        $form->add($saveButton);

        // Add Alpine.js script for functionality
        $alpineScript = $this->getAlpineScript();

        return $alpineScript.$header.$subheader.$form->render();
    }

    private function renderDynamicFieldsets(): string {
        $templateOptions = $this->promptAI->getTemplateOptions();
        $fieldOptions = $this->promptAI->getFieldOptions();

        // Translatable strings
        $noConfigsMessage = _('No prompt configurations defined yet. Click "Add New Prompt Configuration" to get started.');
        $configurationLabel = _('Prompt Configuration');
        $untitledLabel = _('Untitled');
        $removeLabel = _('Remove');
        $templateLabel = _('Template');
        $templateHint = _('(leave empty for all templates)');
        $allTemplatesOption = _('-- All Templates --');
        $sourceFieldLabel = _('Source Field');
        $requiredMark = _('*');
        $selectSourceOption = _('-- Select Source Field --');
        $targetFieldLabel = _('Target Field');
        $targetFieldHint = _('(leave empty to use source field)');
        $useSourceOption = _('-- Use Source Field --');
        $labelFieldLabel = _('Label');
        $labelFieldHint = _('(for identification)');
        $labelPlaceholder = _('Optional label for this configuration');
        $promptLabel = _('Prompt');
        $promptPlaceholder = _('Enter your AI prompt here...');
        $addPromptLabel = _('Add New Prompt Configuration');

        $html = '<div x-show="fieldsets.length === 0" class="notice"><p>'.$noConfigsMessage.'</p></div>';
        $html .= '<ul class="prompt-config-items" style="padding-left: 0;" x-show="fieldsets.length > 0">';
        $html .= '<template x-for="(fieldset, index) in fieldsets" :key="index">';
        $html .= '<li class="Inputfield InputfieldFieldset uk-grid-margin uk-first-column InputfieldColumnWidthFirst prompt-config-item">';
        $html .= '<label class="InputfieldHeader uk-form-label InputfieldStateToggle">';
        $html .= '<span class="item-controls" style="margin-right: 10px;">';
        $html .= '<i class="fa fa-trash btn-remove" x-on:click="removeFieldset(index)" title="' . $removeLabel . '"></i>';
        $html .= '</span>';
        $html .= '<span class="item-label" x-text="`'.$configurationLabel.' ${index + 1}: ${fieldset.label || &quot;'.$untitledLabel.'&quot;}`"></span>';
        $html .= '</label>';
        $html .= '<div class="InputfieldContent uk-form-controls">';
        $html .= '<ul class="Inputfields uk-grid uk-grid-collapse uk-grid-match uk-grid-stack" uk-grid>';

        // Label field
        $html .= '<li class="Inputfield InputfieldText InputfieldColumnWidth uk-grid-margin" data-colwidth="50%" style="width: 50%;">';
        $html .= '<label class="InputfieldHeader uk-form-label">'.$labelFieldLabel.' <small>'.$labelFieldHint.'</small></label>';
        $html .= '<div class="InputfieldContent uk-form-controls">';
        $html .= '<input type="text" class="uk-input" x-model="fieldset.label" :name="`label_${index}`" placeholder="'.$labelPlaceholder.'" />';
        $html .= '</div></li>';

        // Template field
        $html .= '<li class="Inputfield InputfieldSelect InputfieldColumnWidth uk-grid-margin" data-colwidth="50%" style="width: 50%;">';
        $html .= '<label class="InputfieldHeader uk-form-label">'.$templateLabel.' <small>'.$templateHint.'</small></label>';
        $html .= '<div class="InputfieldContent uk-form-controls">';
        $html .= '<select class="uk-select" x-model="fieldset.template" :name="`template_${index}`">';
        $html .= '<option value="">'.$allTemplatesOption.'</option>';
        foreach ($templateOptions as $id => $name) {
            $html .= '<option value="'.$id.'">'.$name.'</option>';
        }
        $html .= '</select>';
        $html .= '</div></li>';

        // Source field
        $html .= '<li class="Inputfield InputfieldSelect InputfieldColumnWidth uk-grid-margin" data-colwidth="50%" style="width: 50%;">';
        $html .= '<label class="InputfieldHeader uk-form-label">'.$sourceFieldLabel.' <span class="required">'.$requiredMark.'</span></label>';
        $html .= '<div class="InputfieldContent uk-form-controls">';
        $html .= '<select class="uk-select" x-model="fieldset.sourceField" :name="`sourceField_${index}`" required>';
        $html .= '<option value="">'.$selectSourceOption.'</option>';
        foreach ($fieldOptions as $id => $name) {
            $html .= '<option value="'.$id.'">'.$name.'</option>';
        }
        $html .= '</select>';
        $html .= '</div></li>';

        // Target field
        $html .= '<li class="Inputfield InputfieldSelect InputfieldColumnWidth uk-grid-margin" data-colwidth="50%" style="width: 50%;">';
        $html .= '<label class="InputfieldHeader uk-form-label">'.$targetFieldLabel.' <small>'.$targetFieldHint.'</small></label>';
        $html .= '<div class="InputfieldContent uk-form-controls">';
        $html .= '<select class="uk-select" x-model="fieldset.targetField" :name="`targetField_${index}`">';
        $html .= '<option value="">'.$useSourceOption.'</option>';
        foreach ($fieldOptions as $id => $name) {
            $html .= '<option value="'.$id.'">'.$name.'</option>';
        }
        $html .= '</select>';
        $html .= '</div></li>';

        // Prompt textarea (full width)
        $html .= '<li class="Inputfield InputfieldTextarea uk-grid-margin" style="width: 100%;">';
        $html .= '<label class="InputfieldHeader uk-form-label">'.$promptLabel.' <span class="required">'.$requiredMark.'</span></label>';
        $html .= '<div class="InputfieldContent uk-form-controls">';
        $html .= '<textarea class="uk-textarea" x-model="fieldset.prompt" :name="`prompt_${index}`" rows="4" required placeholder="'.$promptPlaceholder.'"></textarea>';
        $html .= '</div></li>';

        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</li>';
        $html .= '</template>';
        $html .= '</ul>';

        $html .= '<a class="" href="#" x-on:click.prevent="addFieldset()"><i class="fa fa-fw fa-plus-circle" data-on="fa-spin fa-spinner" data-off="fa-plus-circle"></i>'.$addPromptLabel.'</a>';

        return $html;
    }

    private function getAlpineScript(): string {
        // Convert existing prompt matrix to JavaScript for initial data
        $initialData = [];
        foreach ($this->promptMatrix as $entity) {
            $initialData[] = [
                'template' => $entity->template ?: '',
                'sourceField' => $entity->sourceField ?: '',
                'targetField' => $entity->targetField ?: '',
                'prompt' => $entity->prompt ?: '',
                'label' => $entity->label ?: '',
            ];
        }

        // If no existing data, start with one empty fieldset
        if (empty($initialData)) {
            $initialData[] = [
                'template' => '',
                'sourceField' => '',
                'targetField' => '',
                'prompt' => '',
                'label' => '',
            ];
        }

        $initialDataJson = json_encode($initialData);

        return "
        <script>
        function promptConfigForm() {
            return {
                fieldsets: {$initialDataJson},
                addFieldset() {
                    this.fieldsets.push({
                        template: '',
                        sourceField: '',
                        targetField: '',
                        prompt: '',
                        label: ''
                    });
                    
                    // Scroll to the new fieldset after it's been added to the DOM
                    setTimeout(() => {
                        const newFieldsets = document.querySelectorAll('.prompt-config-item');
                        const lastFieldset = newFieldsets[newFieldsets.length - 1];
                        if (lastFieldset) {
                            lastFieldset.scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'center' 
                            });
                        }
                    }, 100);
                },
                removeFieldset(index) {
                    this.fieldsets.splice(index, 1);
                }
            }
        }
        </script>";
    }

    public function processSubmission(): void {
        $input = wire('input');
        $configData = [];

        // Process submitted form data
        $maxIndex = 0;
        foreach ($input->post as $key => $value) {
            if (preg_match('/^(template|sourceField|targetField|prompt|label)_(\d+)$/', $key, $matches)) {
                $fieldType = $matches[1];
                $index = (int)$matches[2];
                $maxIndex = max($maxIndex, $index);

                if (!isset($configData[$index])) {
                    $configData[$index] = [];
                }
                $configData[$index][$fieldType] = $value;
            }
        }

        // Convert to JSON format
        $jsonConfig = [];
        for ($i = 0; $i <= $maxIndex; $i++) {
            if (!isset($configData[$i])) {
                continue;
            }

            $config = $configData[$i];

            // Skip if required fields are missing
            if (empty($config['sourceField']) || empty($config['prompt'])) {
                continue;
            }

            $template = !empty($config['template']) ? (int)$config['template'] : null;
            $sourceField = (int)$config['sourceField'];
            $targetField = !empty($config['targetField']) ? (int)$config['targetField'] : null;
            $prompt = $config['prompt'] ?? '';
            $label = $config['label'] ?? '';

            $jsonConfig[] = [
                'template' => $template,
                'sourceField' => $sourceField,
                'targetField' => $targetField,
                'prompt' => $prompt,
                'label' => $label
            ];
        }

        $promptMatrixString = json_encode($jsonConfig, JSON_PRETTY_PRINT);

        // Save to module configuration
        $moduleConfig = wire('modules')->getConfig('PromptAI');
        $moduleConfig['promptMatrix'] = $promptMatrixString;
        wire('modules')->saveConfig('PromptAI', $moduleConfig);

        wire('session')->message(_('Prompt configuration saved successfully!'));
    }
}
