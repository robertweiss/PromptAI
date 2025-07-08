<?php namespace ProcessWire;

require_once __DIR__.'/PromptAIHelper.php';

class PromptAIConfigForm {
    private Module $promptAI;
    private array $promptMatrix;

    public function __construct() {
        /** @var Module promptAI */
        $this->promptAI = wire('modules')->get('PromptAI');
        $this->promptMatrix = PromptAIHelper::parsePromptMatrix($this->promptAI->get('promptMatrix'));
    }

    public function render(): string {
        $moduleUrl = wire('config')->urls->siteModules.'PromptAI/';
        wire('config')->scripts->add($moduleUrl.'alpine.min.js');
        wire('config')->styles->add($moduleUrl."styles.css");

        $out = '';

        // Add Alpine.js script for functionality
        $out .= $this->getAlpineScript();

        $out .= '<h2>'._('Prompt Configuration').'</h2>';
        $out .= '<p>'._('Configure the AI prompts that should be used for the different fields. If the source field is an image/file field, the target field is interpreted as a custom subfield of the image/file field (if left empty, the description is used as the target instead).').'</p>';

        /** @var InputfieldForm $form */
        $form = wire('modules')->get('InputfieldForm');
        $form->attr('id', 'prompt-config-form');
        $form->attr('method', 'post');
        $form->attr('action', './');
        $form->attr('x-data', 'promptConfigForm()');

        // Notice of no configurations set
        $noConfigLabel = '<div x-show="fieldsets.length === 0" class="notice"><p>'._('No prompt configurations defined yet. Click "Add New Prompt Configuration" to get started.').'</p></div>';

        // Wrap fieldset rendering in <template> for Alpine.js
        $fieldsetTemplate = '
            <template x-for="(fieldset, index) in fieldsets" :key="index">'.$this->createFieldset($form)->render().'</template>';

        $addFieldsetButton = '
            <a class="" href="#" x-on:click.prevent="addFieldset()">
                <i class="fa fa-fw fa-plus-circle" data-on="fa-spin fa-spinner" data-off="fa-plus-circle"></i>
                '._('Add New Prompt Configuration').'
            </a>
        ';

        // Fieldset
        $container = $form->InputfieldMarkup;
        $container->label = '';
        $container->value = $noConfigLabel.$fieldsetTemplate.$addFieldsetButton;
        $form->add($container);

        // Hidden submit field
        $submit = $form->InputfieldHidden;
        $submit->attr('name', 'submit_prompt_config');
        $submit->attr('value', '1');
        $form->add($submit);

        // Hidden field to store the actual configuration data
        $configData = $form->InputfieldHidden;
        $configData->attr('name', 'prompt_config_data');
        $configData->attr('x-bind:value', 'JSON.stringify(fieldsets)');
        $form->add($configData);

        // Save button
        $saveButton = $form->InputfieldSubmit;
        $saveButton->attr('name', 'save');
        $saveButton->attr('value', 'Save Configuration');
        $saveButton->attr('class', 'ui-button ui-widget ui-corner-all ui-button-text-only pw-head-button ui-state-default');
        $form->add($saveButton);

        // Reset custom markup which was set for fieldset in createFieldset() (seems to be inherited)
        $form->setMarkup(['list' => '<ul {attrs}>{out}</ul>']);

        $out .= $form->render();

        return $out;
    }

    private function createFieldset(InputfieldForm $form): InputfieldFieldset {
        $fieldset = $form->InputfieldFieldset;
        $fieldset->attr(['class' => 'prompt-ai-config--item-content', 'x-show' => 'fieldsets.length > 0']);

        // Fieldset Header
        $configurationLabel = _('Prompt Configuration');
        $untitledLabel = _('Untitled');
        $removeLabel = _('Remove');
        $headerHtml = '
            <div class="prompt-ai-config--item-header InputfieldHeader">
                <span class="prompt-ai-config--item-controls">
                    <i class="fa fa-trash btn-remove" x-on:click="removeFieldset(index)" title="'.$removeLabel.'"></i>
                </span>
                <span class="prompt-ai-config--item-label" x-text="`'.$configurationLabel.' ${index + 1}: ${fieldset.label || &quot;'.$untitledLabel.'&quot;}`">
                </span>
            </div>
        ';

        $fieldset->setMarkup(['list' => '<div class="prompt-ai-config--item InputfieldFieldset">'.$headerHtml."<ul {attrs}>{out}</ul></div>",]);

        // Fieldset Label
        /** @var InputfieldText $field */
        $field = $fieldset->InputfieldText;
        $field->label = _('Label');
        $field->notes = _('(for identification, optional)');
        $field->attr(['x-model' => 'fieldset.label']);
        $field->columnWidth = 66;
        $fieldset->add($field);

                // Fieldset Overwrite Target
        /** @var InputfieldCheckbox $field */
        $field = $fieldset->InputfieldCheckbox;
        $field->label = _('Overwrite target field content');
        $field->description = '';
        $field->notes = _('When unchecked, only fills empty fields');
        $field->attr(['x-model' => 'fieldset.overwriteTarget', 'value' => 0]);
        $field->columnWidth = 34;
        $fieldset->add($field);

        // Fieldset Template
        /** @var InputfieldSelect $field */
        $field = $fieldset->InputfieldAsmSelect;
        $field->label = _('Template(s)');
        $field->notes = _('(leave empty for all templates)');
        $field->class = 'uk-select';
        $field->attr(['x-model' => 'fieldset.template']);
        $field->options = PromptAIHelper::getTemplateOptions();
        $field->columnWidth = 33;
        $fieldset->add($field);

        // Fieldset Source Field
        /** @var InputfieldSelect $field */
        $field = $fieldset->InputfieldSelect;
        $field->label = _('Source Field');
        $field->notes = _('(required)');
        $field->attr(['x-model' => 'fieldset.sourceField', 'required' => 'required']);
        $field->options = ['' => _('-- Select Source Field --')] + PromptAIHelper::getFieldOptions();
        $field->columnWidth = 33;
        $fieldset->add($field);

        // Fieldset Target Field
        /** @var InputfieldSelect $field */
        $field = $fieldset->InputfieldSelect;
        $field->label = _('Target Field');
        $field->notes = _('(leave empty to use source field)');
        $field->attr(['x-model' => 'fieldset.targetField']);
        $field->options = ['' => _('-- Use Source Field --')] + PromptAIHelper::getFieldOptions();
        $field->columnWidth = 34;
        $fieldset->add($field);

        // Fieldset Prompt
        /** @var InputfieldTextarea $field */
        $field = $fieldset->InputfieldTextarea;
        $field->label = _('Prompt');
        $field->notes = _('(required)');
        $field->attr(['rows' => 4, 'x-model' => 'fieldset.prompt', 'required' => 'required']);
        $field->columnWidth = 100;
        $fieldset->add($field);

        return $fieldset;
    }

    private function getAlpineScript(): string {
        // Convert existing prompt matrix to JavaScript for initial data
        $initialData = [];

        foreach ($this->promptMatrix as $entity) {
            $initialData[] = [
                'template' => $entity->template ?: [], // Template is always an array
                'sourceField' => $entity->sourceField ?: '',
                'targetField' => $entity->targetField ?: '',
                'prompt' => $entity->prompt ?: '',
                'label' => $entity->label ?: '',
                'overwriteTarget' => $entity->overwriteTarget ?? false,
            ];
        }

        // If no existing data, start with one empty fieldset
        if (empty($initialData)) {
            $initialData[] = [
                'template' => [],
                'sourceField' => '',
                'targetField' => '',
                'prompt' => '',
                'label' => '',
                'overwriteTarget' => false,
            ];
        }

        $initialDataJson = json_encode($initialData);

        return "
        <script>
        function promptConfigForm() {
            return {
                fieldsets: {$initialDataJson},
                init() {
                    this.setupAsmSelectListener();
                },
                setupListener() {
                    const self = this;
                    // Find all select elements with x-model containing 'template'
                    $('select[x-model*=\"template\"]').off('change.asmAlpine').on('change.asmAlpine', function(e, data) {
                        const target = this;
                        const xModel = target.getAttribute('x-model');
                        
                        if (xModel === 'fieldset.template') {
                            // Find the fieldset index by looking at the DOM structure
                            // Each fieldset is wrapped in a .prompt-ai-config--item container
                            const fieldsetContainer = target.closest('.prompt-ai-config--item');
                            if (fieldsetContainer) {
                                // Get all fieldset containers and find the index of this one
                                const allFieldsetContainers = document.querySelectorAll('.prompt-ai-config--item');
                                const fieldsetIndex = Array.from(allFieldsetContainers).indexOf(fieldsetContainer);
                                
                                if (fieldsetIndex !== -1 && self.fieldsets[fieldsetIndex]) {
                                    // Get selected values from the select element
                                    const selectedValues = Array.from(target.options)
                                        .filter(option => option.selected)
                                        .map(option => option.value);
                                    self.fieldsets[fieldsetIndex].template = selectedValues;
                                    
                                    // Log for debugging
//                                    console.log('asmSelect change detected:', {
//                                        fieldsetIndex: fieldsetIndex,
//                                        selectedValues: selectedValues,
//                                        data: data // This contains the asmSelect event data
//                                    });
                                }
                            }
                        }
                    });
                },
                setupAsmSelectListener() {
                    // Set up listeners now and after DOM changes
                    if (typeof $ !== 'undefined') {
                        this.setupListener();
                        // Also set up after a brief delay to catch any late-loading elements
                        setTimeout(() => this.setupListener(), 100);
                    }
                },
                addFieldset() {
                    this.fieldsets.push({
                        template: [],
                        sourceField: '',
                        targetField: '',
                        prompt: '',
                        label: '',
                        overwriteTarget: false
                    });
                    
                    // Initialize new asmSelect fields and scroll after DOM update
                    setTimeout(() => {
                        const fieldsetsEls = document.querySelectorAll('.prompt-ai-config--item');
                        const lastFieldsetEl = fieldsetsEls[fieldsetsEls.length - 1];
                        
                        if (lastFieldsetEl) {
                            // Initialize any new InputfieldAsmSelect elements in the new fieldset
                            const newAsmSelects = lastFieldsetEl.querySelectorAll('.InputfieldAsmSelect select[multiple]');
                            newAsmSelects.forEach(select => {
                                if (typeof initInputfieldAsmSelect === 'function') {
                                    // ProcessWire's initInputfieldAsmSelect expects a jQuery object
                                    initInputfieldAsmSelect($(select));
                                }
                            });
                            
                            // Refresh the global event listeners to include new elements
                            setTimeout(() => {
                                this.setupListener();
                            }, 50);
                            
                            // Scroll to the new fieldset
                            lastFieldsetEl.scrollIntoView({ 
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

        // Get the JSON data from the hidden field
        $configDataJson = $input->post->text('prompt_config_data', ['maxLength' => 0]);

        if (empty($configDataJson)) {
            wire('session')->error(_('No configuration data received.'));

            return;
        }
        // Parse the JSON data
        $configData = json_decode($configDataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wire('session')->error(_('Invalid configuration data format.'));

            return;
        }

        // Convert to the expected format
        $jsonConfig = [];
        foreach ($configData as $config) {
            // Skip if required fields are missing
            if (empty($config['sourceField']) || empty($config['prompt'])) {
                continue;
            }

            // Handle template as array only
            $template = null;
            if (!empty($config['template']) && is_array($config['template'])) {
                $template = array_map('intval', array_filter($config['template']));
                $template = !empty($template) ? $template : null;
            }
            $sourceField = (int)$config['sourceField'];
            $targetField = !empty($config['targetField']) ? (int)$config['targetField'] : null;
            $prompt = $config['prompt'] ?? '';
            $label = $config['label'] ?? '';
            $overwriteTarget = $config['overwriteTarget'] ?? false;

            $jsonConfig[] = [
                'template' => $template,
                'sourceField' => $sourceField,
                'targetField' => $targetField,
                'prompt' => $prompt,
                'label' => $label,
                'overwriteTarget' => $overwriteTarget,
            ];
        }

        $promptMatrixString = json_encode($jsonConfig, JSON_PRETTY_PRINT);

        // Save to module configuration
        $moduleConfig = wire('modules')->getConfig('PromptAI');

        $moduleConfig['promptMatrix'] = $promptMatrixString;
        $saveResult = wire('modules')->saveConfig('PromptAI', $moduleConfig);

        if ($saveResult) {
            wire('session')->message(_('Prompt configuration saved successfully!'));
        } else {
            wire('session')->error(_('Failed to save prompt configuration.'));
        }
    }
}
