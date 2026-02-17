<?php namespace ProcessWire;

require_once __DIR__.'/PromptAIHelper.php';

class PromptAIConfigForm {
    private Module $promptAI;
    private array $promptMatrix;

    public function __construct() {
        /** @var Module promptAI */
        $this->promptAI = wire('modules')->get('PromptAI');
        $this->promptMatrix = PromptAIHelper::parsePromptMatrix($this->promptAI->get('promptMatrix'), false);
    }

    public function render(): string {
        $moduleUrl = wire('config')->urls->siteModules.'PromptAI/';
        wire('config')->scripts->add($moduleUrl.'alpine.min.js');
        wire('config')->styles->add($moduleUrl."styles.css");

        $out = '';
        $moduleConfig = wire('modules')->getConfig('PromptAI');

        // Add Alpine.js script for functionality
        $out .= $this->getAlpineScript();

        $out .= '<h2>'.__('Prompt Configuration').'</h2>';
        $out .= '<p>'.__('Configure AI prompts for your fields. Each prompt can work in two modes:').'</p>';
        $out .= '<ul>';
        $out .= '<li><strong>'.__('Inline Mode').':</strong> '.__('Prompt buttons appear below field(s) for instant AI processing').'</li>';
        $out .= '<li><strong>'.__('Page Mode').':</strong> '.__('Processes field(s) when clicking "Save + Send to AI" button').'</li>';
        $out .= '</ul>';

        $provider = $moduleConfig['provider'] ?? '';
        if ($provider === 'deepseek') {
            $out .= '<p class="uk-text-danger uk-alert">'.__('Attention: DeepSeek does not currently support file or image fields.').'</p>';
        }

        /** @var InputfieldForm $form */
        $form = wire('modules')->get('InputfieldForm');
        $form->attr('id', 'prompt-config-form');
        $form->attr('method', 'post');
        $form->attr('action', './');
        $form->attr('x-data', 'promptConfigForm()');
        $form->attr('x-on:submit', 'validateForm($event)');

        // Notice of no configurations set
        $noConfigLabel = '<div x-show="fieldsets.length === 0" class="notice"><p>'.__('No prompt configurations defined yet. Click "Add New Prompt Configuration" to get started.').'</p></div>';

        // Wrap fieldset rendering in <template> for Alpine.js
        $fieldsetTemplate = '
            <template x-for="(fieldset, index) in fieldsets" :key="index">'.$this->createFieldset($form)->render().'</template>';

        $addFieldsetButton = '
            <a class="" href="#" x-on:click.prevent="addFieldset()">
                <i class="fa fa-fw fa-plus-circle" data-on="fa-spin fa-spinner" data-off="fa-plus-circle"></i>
                '.__('Add New Prompt Configuration').'
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
        $configurationLabel = __('Prompt Configuration');
        $untitledLabel = __('Untitled');
        $removeLabel = __('Remove');
        $headerHtml = '
            <div class="prompt-ai-config--item-header InputfieldHeader">
                <span class="prompt-ai-config--item-controls">
                    <i class="fa fa-trash btn-remove" x-on:click="removeFieldset(index)" title="'.$removeLabel.'"></i>
                </span>
                <span class="prompt-ai-config--item-label" x-text="`'.$configurationLabel.' ${index + 1}: ${fieldset.label || &quot;'.$untitledLabel.'&quot;}`">
                </span>
            </div>
        ';

        $errorContainerHtml = '
            <div class="promptai-validation-errors" x-show="Object.keys(errors[index] || {}).length > 0" x-cloak>
                <template x-for="(msg, key) in (errors[index] || {})">
                    <div class="promptai-error-msg"><i class="fa fa-exclamation-triangle"></i> <span x-text="msg"></span></div>
                </template>
            </div>
        ';

        $fieldset->setMarkup(['list' => '<div class="prompt-ai-config--item InputfieldFieldset">'.$headerHtml.$errorContainerHtml."<ul {attrs}>{out}</ul></div>",]);

        // Fieldset Label
        /** @var InputfieldText $field */
        $field = $fieldset->InputfieldText;
        $field->label = __('Label');
        $field->notes = __('(required)');
        $field->attr(['x-model' => 'fieldset.label']);
        $field->columnWidth = 50;
        $fieldset->add($field);

        // Mode selector (inline or page) - Using markup for proper Alpine.js binding
        /** @var InputfieldMarkup $field */
        $field = $fieldset->InputfieldMarkup;
        $field->label = __('Mode');
        $field->notes = __('(Inline: button on field, Page: button on save)');
        $field->columnWidth = 50;
        $inlineModeLabel = __('Inline Mode');
        $pageModeLabel = __('Page Mode');
        $field->value = '
            <div class="InputfieldRadiosStacked">
                <label style="display: inline-block; margin-right: 20px;">
                    <input class="uk-radio" type="radio" value="inline" x-model="fieldset.mode" x-bind:name="\'mode_\' + index">
                    ' . $inlineModeLabel . '
                </label>
                <label style="display: inline-block;">
                    <input class="uk-radio" type="radio" value="page" x-model="fieldset.mode" x-bind:name="\'mode_\' + index">
                    ' . $pageModeLabel . '
                </label>
            </div>
        ';
        $fieldset->add($field);

        // Template selector (optional, null = all templates)
        /** @var InputfieldAsmSelect $field */
        $field = $fieldset->InputfieldAsmSelect;
        $field->label = __('Template(s)');
        $field->notes = __('(leave empty for all templates)');
        $field->class = 'uk-select';
        $field->attr(['x-model' => 'fieldset.templates']);
        $field->options = PromptAIHelper::getTemplateOptions();
        $field->columnWidth = 50;
        $fieldset->add($field);

        // Fields selector (required, multiple selection)
        /** @var InputfieldAsmSelect $field */
        $field = $fieldset->InputfieldAsmSelect;
        $field->label = __('Field(s)');
        $field->notes = __('(select one or more fields, required)');
        $field->class = 'uk-select';
        $field->attr(['x-model' => 'fieldset.fields']);
        $field->options = PromptAIHelper::getFieldOptions();
        $field->columnWidth = 50;
        $fieldset->add($field);

        // Overwrite Target (page mode only)
        /** @var InputfieldCheckbox $field */
        $field = $fieldset->InputfieldCheckbox;
        $field->label = __('Overwrite field content');
        $field->notes = __('When unchecked, only processes empty fields.');
        $field->attr(['x-model' => 'fieldset.overwriteTarget', 'value' => 0]);
        $field->wrapAttr('x-show', "fieldset.mode !== 'inline'");
        $field->wrapAttr('x-bind:style', "optionWidth(fieldset, 'overwrite')");
        $fieldset->add($field);

        // Ignore field content
        /** @var InputfieldCheckbox $field */
        $field = $fieldset->InputfieldCheckbox;
        $field->label = __('Ignore field content');
        $field->notes = __('Send only the prompt, without the current text of the field. Files/images are still sent.');
        $field->attr(['x-model' => 'fieldset.ignoreFieldContent', 'value' => 0]);
        $field->wrapAttr('x-bind:style', "optionWidth(fieldset, 'ignore')");
        $fieldset->add($field);

        // Target Subfield (file/image fields only)
        /** @var InputfieldText $field */
        $field = $fieldset->InputfieldText;
        $field->label = __('Target Subfield');
        $field->notes = __('(required for file/image fields)');
        $field->attr(['x-model' => 'fieldset.targetSubfield', 'placeholder' => 'description']);
        $field->wrapAttr('x-bind:style', "hasFileField(fieldset.fields) ? optionWidth(fieldset, 'subfield') : 'display:none'");
        $fieldset->add($field);

        // Prompt textarea
        /** @var InputfieldTextarea $field */
        $field = $fieldset->InputfieldTextarea;
        $field->label = __('Prompt');
        $field->notes = __('(required)');
        $field->attr(['rows' => 4, 'x-model' => 'fieldset.prompt']);
        $field->columnWidth = 100;
        $fieldset->add($field);

        return $fieldset;
    }

    private function getAlpineScript(): string {
        // Convert existing prompt matrix to JavaScript for initial data
        $initialData = [];

        foreach ($this->promptMatrix as $entity) {
            $initialData[] = $entity->toArray();
        }

        // If no existing data, start with one empty fieldset
        if (empty($initialData)) {
            $initialData[] = (new PromptMatrixEntity())->toArray();
        }

        $initialDataJson = json_encode($initialData);
        $emptyFieldsetJson = json_encode((new PromptMatrixEntity())->toArray());

        // Collect file/image field IDs for conditional display of targetSubfield
        $fileFieldIds = [];
        if (wire('fields')) {
            foreach (wire('fields') as $field) {
                if (in_array(get_class($field->type), PromptAIHelper::$fileFieldTypes)) {
                    $fileFieldIds[] = (string)$field->id;
                }
            }
        }
        $fileFieldIdsJson = json_encode($fileFieldIds);

        $errorMessages = json_encode([
            'label' => __('Label is required'),
            'mode' => __('Mode must be selected'),
            'fields' => __('At least one field must be selected'),
            'targetSubfield' => __('Target subfield is required when file/image fields are selected'),
            'prompt' => __('Prompt is required'),
        ]);

        return "
        <script>
        function promptConfigForm() {
            return {
                fieldsets: {$initialDataJson},
                errors: [],
                fileFieldIds: {$fileFieldIdsJson},
                errorMessages: {$errorMessages},
                init() {
                    this.errors = this.fieldsets.map(() => ({}));
                    this.setupAsmSelectListener();
                },
                validateForm(event) {
                    let isValid = true;

                    this.fieldsets.forEach((fieldset, index) => {
                        this.errors[index] = {};

                        if (!fieldset.label || !fieldset.label.trim()) {
                            this.errors[index].label = this.errorMessages.label;
                            isValid = false;
                        }

                        if (!fieldset.mode || !['inline', 'page'].includes(fieldset.mode)) {
                            this.errors[index].mode = this.errorMessages.mode;
                            isValid = false;
                        }

                        const hasFields = fieldset.fields && Array.isArray(fieldset.fields) && fieldset.fields.length > 0;
                        if (!hasFields) {
                            this.errors[index].fields = this.errorMessages.fields;
                            isValid = false;
                        }

                        if (this.hasFileField(fieldset.fields) && (!fieldset.targetSubfield || !fieldset.targetSubfield.trim())) {
                            this.errors[index].targetSubfield = this.errorMessages.targetSubfield;
                            isValid = false;
                        }

                        if (!fieldset.prompt || !fieldset.prompt.trim()) {
                            this.errors[index].prompt = this.errorMessages.prompt;
                            isValid = false;
                        }
                    });

                    if (!isValid) {
                        event.preventDefault();
                        event.stopPropagation();
                        this.\$nextTick(() => {
                            const firstError = document.querySelector('.promptai-validation-errors:not([style*=\"display: none\"])');
                            if (firstError) {
                                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        });
                    }
                },
                setupListener() {
                    const self = this;
                    // Find all select elements with x-model containing 'template' or 'fields'
                    $('select[x-model*=\"templates\"], select[x-model*=\"fields\"]').off('change.asmAlpine').on('change.asmAlpine', function(e, data) {
                        const target = this;
                        const xModel = target.getAttribute('x-model');

                        if (xModel === 'fieldset.templates' || xModel === 'fieldset.fields') {
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

                                    // Update the appropriate field in the Alpine data
                                    if (xModel === 'fieldset.templates') {
                                        self.fieldsets[fieldsetIndex].templates = selectedValues;
                                    } else if (xModel === 'fieldset.fields') {
                                        self.fieldsets[fieldsetIndex].fields = selectedValues;
                                    }
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
                hasFileField(fields) {
                    if (!fields || !Array.isArray(fields)) return false;
                    return fields.some(id => this.fileFieldIds.includes(String(id)));
                },
                optionWidth(fieldset, which) {
                    const isPageMode = fieldset.mode !== 'inline';
                    const hasFile = this.hasFileField(fieldset.fields);
                    let count = 1; // ignoreFieldContent is always visible
                    if (isPageMode) count++;
                    if (hasFile) count++;
                    if (count === 1) return 'width: 100%';
                    if (count === 2) return 'width: 50%';
                    return which === 'overwrite' ? 'width: 34%' : 'width: 33%';
                },
                addFieldset() {
                    this.fieldsets.push(JSON.parse('{$emptyFieldsetJson}'));
                    this.errors.push({});

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
                    this.errors.splice(index, 1);
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
            wire('session')->error(__('No configuration data received.'));

            return;
        }
        // Parse the JSON data
        $configData = json_decode($configDataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wire('session')->error(__('Invalid configuration data format.'));

            return;
        }

        // Get module config
        $moduleConfig = wire('modules')->getConfig('PromptAI');

        // Convert to the expected format
        $jsonConfig = [];
        foreach ($configData as $config) {
            // Mode is required
            if (empty($config['mode']) || !in_array($config['mode'], ['inline', 'page'])) {
                continue;
            }

            // Prompt is required
            if (empty($config['prompt'])) {
                continue;
            }

            // Fields array is required
            if (empty($config['fields']) || !is_array($config['fields'])) {
                continue;
            }

            // Handle template as array (optional, null = all templates)
            $template = null;
            if (!empty($config['templates']) && is_array($config['templates'])) {
                $template = array_map('intval', array_filter($config['templates']));
                $template = !empty($template) ? $template : null;
            }

            // Fields array - convert to integers
            $fields = array_map('intval', array_filter($config['fields']));
            if (empty($fields)) {
                continue; // Skip if no valid field IDs
            }

            $jsonConfig[] = PromptMatrixEntity::fromArray([
                'mode' => $config['mode'],
                'templates' => $template,
                'fields' => $fields,
                'prompt' => $config['prompt'] ?? '',
                'label' => $config['label'] ?? '',
                'overwriteTarget' => $config['overwriteTarget'] ?? false,
                'targetSubfield' => $config['targetSubfield'] ?? 'description',
                'ignoreFieldContent' => $config['ignoreFieldContent'] ?? false,
            ])->toArray();
        }

        $promptMatrixString = json_encode($jsonConfig, JSON_PRETTY_PRINT);

        // Save to module configuration
        $moduleConfig['promptMatrix'] = $promptMatrixString;
        $saveResult = wire('modules')->saveConfig('PromptAI', $moduleConfig);

        if ($saveResult) {
            wire('session')->message(__('Prompt configuration saved successfully!'));
        } else {
            wire('session')->error(__('Failed to save prompt configuration.'));
        }
    }
}
