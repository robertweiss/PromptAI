<?php namespace ProcessWire;

require_once __DIR__.'/vendor/autoload.php';

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Deepseek\Deepseek;

class PromptAIAgent extends Agent {
    private ?string $apiKey;
    private ?string $providerName;
    private ?string $modelName;
    private ?string $systemPrompt;
    private bool $enableTools;

    public function __construct(array $options = []) {
        if (!isset($options['apiKey']) || !isset($options['provider']) || !isset($options['model'])) {
            throw new \Exception('API key, provider and model are required');
        }
        $this->apiKey = $options['apiKey'];
        $this->providerName = $options['provider'];
        $this->modelName = $options['model'];
        $this->systemPrompt = $options['systemPrompt'];
        $this->enableTools = (bool)($options['enableTools'] ?? false);
        $this->provider = $this->provider();
    }

    protected function provider(): AIProviderInterface {
        if ($this->providerName === 'anthropic') {
            return new Anthropic(
                key: $this->apiKey, model: $this->modelName,
            );
        }
        if ($this->providerName === 'openai') {
            return new OpenAI(
                key: $this->apiKey, model: $this->modelName,
            );
        }
        if ($this->providerName === 'gemini') {
            return new Gemini(
                key: $this->apiKey, model: $this->modelName,
            );
        }
        if ($this->providerName === 'deepseek') {
            return new Deepseek(
                key: $this->apiKey, model: $this->modelName,
            );
        }

        throw new \Exception('Provider not supported');
    }

    public function instructions(): string {
        return $this->systemPrompt ?: '';
    }

    protected function tools(): array {
        if (!$this->enableTools) {
            return [];
        }

        $toolkitFile = __DIR__ . '/tools/ProcessWireToolkit.php';
        if (!file_exists($toolkitFile)) {
            return [];
        }

        require_once $toolkitFile;

        return [
            ProcessWireToolkit::make(),
        ];
    }
}
