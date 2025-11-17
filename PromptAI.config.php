<?php namespace ProcessWire;

class PromptMatrixEntity {
    public ?array $template;
    public ?int $sourceField;
    public ?int $targetField;
    public ?string $prompt;
    public ?string $label;
    public ?bool $overwriteTarget;
}

class PromptAIConfig extends ModuleConfig {
    // Parts of the code are adopted from the Jumplinks module, thx!
    // Copyright (c) 2016-17, Mike Rockett

    private array $providers = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'gemini' => 'Gemini',
        'deepseek' => 'DeepSeek',
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
            'systemPrompt' => '',
            'promptMatrix' => '',
            'individualButtons' => 0,
            'overwriteTarget' => 0,
            'testSettings' => 0,
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
                'notes' => $this->_("[Anthropic](https://docs.anthropic.com/en/docs/about-claude/models/all-models),  [OpenAI](https://platform.openai.com/docs/models), [Gemini](https://ai.google.dev/gemini-api/docs/models), [DeepSeek](https://api-docs.deepseek.com/quick_start/pricing)"),
                'columnWidth' => 33,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldText', [
                'name+id' => 'apiKey',
                'label' => $this->_('API Key'),
                'description' => $this->_('You need an API key to use this module.'),
                'notes' => $this->_("[Anthropic](https://console.anthropic.com/settings/keys), [OpenAI](https://platform.openai.com/account/api-keys), [Gemini](https://aistudio.google.com/apikey), [DeepSeek](https://platform.deepseek.com/api_keys)"),
                'columnWidth' => 34,
                'required' => false,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldTextArea', [
                'name+id' => 'systemPrompt',
                'label' => $this->_('System prompt'),
                'description' => $this->_('This text will be used as the system prompt for the AI. It will be prepended to all AI calls.'),
                'notes' => $this->_('Optional'),
                'columnWidth' => 66,
            ])
        );

        $configLabel = $this->_('Prompt Configuration');
        $configUrl = wire('urls')->admin . 'setup/prompt-ai/';
        $configLink = "[{$configLabel}]($configUrl)";
        $inputfields->add(
            $this->buildInputField('InputfieldMarkup', [
                'name+id' => 'promptMatrixHint',
                'label' => $this->_('Prompts'),
                'description' => $this->_('Use the visual configuration interface to manage your prompts: ') . $configLink,
                'columnWidth' => 34,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldCheckbox', [
                'name+id' => 'individualButtons',
                'label' => $this->_('Individual prompt buttons'),
                'description' => $this->_('Show separate "Send to AI" buttons for each prompt configuration instead of one general button.'),
                'notes' => $this->_('When enabled, each prompt configuration will have its own button labeled with the configuration\'s label (or "Send to AI" as fallback)'),
                'value' => 1,
                'checked' => '',
                'columnWidth' => 100,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldHidden', [
                'name+id' => 'promptMatrix',
                'label' => $this->_('Prompts'),
                'description' => $this->_('Prompt configurations are stored in JSON format with template and field IDs. Use the visual configuration interface in Setup > Prompt AI to manage your prompts.'),
                'notes' => $this->_('This field stores the prompt configuration data. Please use the dedicated Prompt AI configuration page to modify settings instead of editing this field directly.'),
                'columnWidth' => 100,
            ])
        );

        if (wire('input')->post('promptMatrix')) {
            PromptAIHelper::parsePromptMatrix(wire('input')->post('promptMatrix'), true);
        }

        $inputfields->add(
            $this->buildInputField('InputfieldHidden', [
                'name+id' => 'overwriteTarget',
                'label' => $this->_('Overwrite target field content (deprecated)'),
                'description' => $this->_('This global setting is deprecated. Use the per-prompt "Overwrite target field content" setting in the Prompt AI configuration instead.'),
                'notes' => $this->_('This setting will be removed in a future version. Please configure overwrite behavior individually for each prompt.'),
                'value' => 0,
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

