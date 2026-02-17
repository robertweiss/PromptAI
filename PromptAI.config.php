<?php namespace ProcessWire;

class PromptMatrixEntity {
    public function __construct(
        public ?string $mode = 'inline',
        public ?array $templates = null,
        public ?array $fields = null,
        public ?string $prompt = null,
        public ?string $label = null,
        public ?bool $overwriteTarget = false,
        public ?string $targetSubfield = 'description',
        public ?bool $ignoreFieldContent = false,
    ) {}

    public static function fromArray(array $config): self {
        $targetSubfield = trim($config['targetSubfield'] ?? 'description');

        return new self(
            mode: $config['mode'] ?? 'inline',
            templates: $config['templates'] ?? null,
            fields: $config['fields'] ?? null,
            prompt: $config['prompt'] ?? null,
            label: $config['label'] ?? null,
            overwriteTarget: $config['overwriteTarget'] ?? false,
            targetSubfield: $targetSubfield ?: 'description',
            ignoreFieldContent: $config['ignoreFieldContent'] ?? false,
        );
    }

    public function toArray(): array {
        return [
            'mode' => $this->mode ?? 'inline',
            'templates' => $this->templates ?? [],
            'fields' => $this->fields ?? [],
            'prompt' => $this->prompt ?? '',
            'label' => $this->label ?? '',
            'overwriteTarget' => $this->overwriteTarget ?? false,
            'targetSubfield' => $this->targetSubfield ?? 'description',
            'ignoreFieldContent' => $this->ignoreFieldContent ?? false,
        ];
    }
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
            'individualButtons' => false,
            'enableTools' => false,
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
                'notes' => $this->_("[Anthropic](https://platform.claude.com/docs/en/about-claude/models/overview),  [OpenAI](https://platform.openai.com/docs/models), [Gemini](https://ai.google.dev/gemini-api/docs/models), [DeepSeek](https://api-docs.deepseek.com/quick_start/pricing)"),
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
                'columnWidth' => 100,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldHidden', [
                'name+id' => 'promptMatrix',
                'label' => $this->_('Prompts'),
                'description' => $this->_('Prompt configurations are stored in JSON format. Use the visual configuration interface in Setup > Prompt AI to manage your prompts.'),
                'notes' => $this->_('This field stores the prompt configuration data. Please use the dedicated Prompt AI configuration page to modify settings.'),
                'columnWidth' => 100,
            ])
        );

        if (wire('input')->post('promptMatrix')) {
            PromptAIHelper::parsePromptMatrix(wire('input')->post('promptMatrix'), true);
        }

        $inputfields->add(
            $this->buildInputField('InputfieldCheckbox', [
                'name+id' => 'individualButtons',
                'label' => $this->_('Individual buttons for page mode'),
                'description' => $this->_('Show separate "Save + {prompt label}" button for each page mode prompt instead of a single "Save + Send to AI" button'),
                'value' => 1,
                'columnWidth' => 100,
            ])
        );

        $inputfields->add(
            $this->buildInputField('InputfieldCheckbox', [
                'name+id' => 'enableTools',
                'label' => $this->_('Enable AI tools'),
                'description' => $this->_('Allow the AI to call ProcessWire API tools (getPages, getPage, getFields) for data retrieval. This lets you write prompts like "list the first 15 blog posts as links".'),
                'value' => 1,
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

