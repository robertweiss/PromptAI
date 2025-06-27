<?php namespace ProcessWire;

class PromptMatrixEntity {
    public ?string $template;
    public ?string $sourceField;
    public ?string $targetField;
    public ?string $prompt;
    public ?string $label;
}

class PromptAIConfig extends ModuleConfig {
    // Parts of the code are adopted from the Jumplinks module, thx!
    // Copyright (c) 2016-17, Mike Rockett

    private array $providers = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'gemini' => 'Gemini',
    ];

    protected function buildInputField($fieldNameId, $meta) {
        $field = wire('modules')->get($fieldNameId);

        foreach ($meta as $metaNames => $metaInfo) {
            $metaNames = explode('+', $metaNames);
            foreach ($metaNames as $metaName) {
                $field->$metaName = $metaInfo;
            }
        }

        return $field;
    }

    public function getDefaults() {
        return [
            'provider' => 'anthropic',
            'model' => '',
            'apiKey' => '',
            'includedTemplates' => [],
            'sourceField' => [],
            'targetField' => [],
            'commandoString' => '',
        ];
    }

    public function getInputFields() {
        $inputfields = parent::getInputfields();

        $inputfields->add(
            $this->buildInputField('InputfieldSelect', [
                'name+id' => 'provider',
                'label' => $this->_('AI Provider'),
                'description' => $this->_('Which AI provider should be used?'),
                'options' => $this->providers,
                'columnWidth' => 33,
                'required' => true,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldText', [
                'name+id' => 'model',
                'label' => $this->_('AI Model'),
                'description' => $this->_('Which AI model should be used?'),
                'notes' => $this->_("Anthropic: https://docs.anthropic.com/en/docs/about-claude/models/all-models\n OpenAI: https://platform.openai.com/docs/models\nGemini: https://ai.google.dev/gemini-api/docs/models"),
                'columnWidth' => 33,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldText', [
                'name+id' => 'apiKey',
                'label' => $this->_('API Key'),
                'description' => $this->_('You need an API key to use this module.'),
                'notes' => $this->_("Anthropic: https://console.anthropic.com/settings/keys\nOpenAI: https://platform.openai.com/account/api-keys\nGemini: https://aistudio.google.com/apikey"),
                'columnWidth' => 34,
                'required' => false,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldTextArea', [
                'name+id' => 'systemPrompt',
                'label' => $this->_('System prompt'),
                'description' => $this->_('This text will be used as the system prompt for the AI. It will be prepended to all AI calls set below'),
                'notes' => $this->_('Optional'),
                'collapsed' => Inputfield::collapsedYes,
                'columnWidth' => 100,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldHidden', [
                'name+id' => 'promptMatrix',
                'label' => $this->_('Prompts'),
                'description' => $this->_('Here you can set the template, the source field, the target field and the prompt for each AI call. You can set multiple calls by adding a new line. Template, source, target and prompt should be seperated by a double colon.'),
                'notes' => $this->_('Example: "home::copy::copy_short::Create a short summary of the following text:". If you want to use the a call for all templates, leave it empty ("::source::target::prompt").'),
                'columnWidth' => 100,
            ])
        );

        if (wire('input')->post('promptMatrix')) {
            wire()->modules('PromptAI')->parsePromptMatrix(wire('input')->post('promptMatrix'), true);
        }

        $inputfields->add(
            $this->buildInputField('InputfieldCheckbox', [
                'name+id' => 'individualButtons',
                'label' => $this->_('Individual prompt buttons'),
                'description' => $this->_('Show separate "Send to AI" buttons for each prompt configuration instead of one general button'),
                'notes' => $this->_('When enabled, each prompt configuration will have its own button labeled with the configuration\'s label (or "Send to AI" as fallback)'),
                'value' => 1,
                'checked' => '',
                'columnWidth' => 100,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldCheckbox', [
                'name+id' => 'testSettings',
                'label' => $this->_('Test settings on save'),
                'description' => $this->_('Send a test request to selected AI'),
                'value' => 1,
                'checked' => '',
                'columnWidth' => 100,
                'collapsed' => Inputfield::collapsedYes,
            ])
        );

        if (wire('input')->post('testSettings')) {
            wire('session')->set('testSettings', 1);
        }

        if (wire('session')->get('testSettings')) {
            $inputfields->add(
                $this->buildInputField('InputfieldMarkup', [
                    'name+id' => 'debug_log',
                    'label' => $this->_('Test results'),
                    'columnWidth' => 100,
                    'value' => $this->requestTest(),
                ])
            );

            // Uncheck testSettings to prevent testing next time the module config is shown
            $moduleConfig = wire('modules')->getConfig('PromptAI');
            $moduleConfig['testSettings'] = 0;
            wire('modules')->saveConfig('PromptAI', $moduleConfig);

            if (!wire('input')->post('testSettings') && wire('session')->get('testSettings')) {
                wire('session')->remove('testSettings');
            }
        }

        return $inputfields;
    }

    private function requestTest() {
        $module = wire('modules')->get('PromptAI');
        $test = $module->testConnection();

        return '<pre>'.$test.'</pre>';
    }
}

