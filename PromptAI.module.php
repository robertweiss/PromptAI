<?php namespace ProcessWire;

require_once __DIR__.'/PromptAIAgent.php';
require_once __DIR__.'/PromptAIConfigForm.php';
require_once __DIR__.'/PromptAIHelper.php';
require_once __DIR__.'/PromptAIInlineMode.php';
require_once __DIR__.'/PromptAIPageMode.php';
require_once __DIR__.'/SSE.php';

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Chat\Attachments\Document;
use NeuronAI\Exceptions\NeuronException;

class PromptAI extends Process implements Module {
    private string $provider;
    private string $model;
    private string $apiKey;
    public ?PromptAIAgent $agent;
    private ?string $systemPrompt;
    private ?string $promptMatrixString;
    private ?array $promptMatrix;
    private int $throttleSave;

    public function ___execute() {
        // Handle SSE streaming requests for inline mode
        if (wire('input')->get('action') === 'inline_process_stream') {
            $this->executeInlineProcessStream();
            return ' ';
        }

        // Handle AJAX requests for inline mode
        if (wire('input')->get('action') === 'inline_process') {
            return $this->executeInlineProcess();
        }

        $configForm = new PromptAIConfigForm();

        // Handle form submission
        if (wire('input')->post('submit_prompt_config')) {
            $configForm->processSubmission();
            $this->session->redirect($this->page->url);
        }

        return $configForm->render();
    }

    private function executeInlineProcess(): string {
        header('Content-Type: application/json');

        try {
            $message = $this->prepareInlineRequest();
            $response = $this->agent->chat($message);
            $result = $response->getContent();

            if (!$result) {
                throw new \Exception(__('AI returned empty response'));
            }

            return json_encode([
                                   'success' => true,
                                   'result' => $result,
                               ]);
        } catch (\Exception $e) {
            return json_encode([
                                   'success' => false,
                                   'error' => $e->getMessage(),
                               ]);
        }
    }

    private function executeInlineProcessStream(): void {
        try {
            $message = $this->prepareInlineRequest();

            SSE::header();

            $response = $this->agent->stream($message);

            foreach ($response as $text) {
                if (!$text) {
                    continue;
                }

                // Skip tool call messages
                if ($text instanceof \NeuronAI\Chat\Messages\ToolCallMessage) {
                    continue;
                }

                SSE::send($text, 'chunk');

                if (connection_aborted()) {
                    break;
                }
            }

            SSE::close();
        } catch (\Exception $e) {
            // If headers haven't been sent yet, start SSE first
            if (!headers_sent()) {
                SSE::header();
            }
            SSE::send($e->getMessage(), 'error');
            SSE::close();
        }
    }

    /**
     * Parse POST parameters, validate, load page/repeater, init agent,
     * resolve file if needed, and return a built UserMessage.
     */
    private function prepareInlineRequest(): UserMessage {
        $content = wire('input')->post->text('content', ['maxLength' => 0]);
        $promptText = wire('input')->post->text('prompt', ['maxLength' => 0]);
        $pageId = wire('input')->post->int('page_id');
        $repeaterItemId = wire('input')->post->int('repeater_item_id');
        $imageFieldName = wire('input')->post->text('image_field');
        $imageBasename = wire('input')->post->text('image_basename');

        if (!$promptText) {
            throw new \Exception(__('No prompt provided'));
        }

        // Only require imageBasename if we're dealing with file fields
        if ($imageFieldName && !$imageBasename) {
            throw new \Exception(__('No file provided'));
        }

        // Get page for context (needed for placeholder substitution)
        $page = null;
        if ($pageId) {
            $page = wire('pages')->get($pageId);
            if (!$page->id) {
                throw new \Exception(__('Page not found'));
            }
        }

        // Get repeater item if provided
        $repeaterItem = null;
        if ($repeaterItemId) {
            $repeaterItem = wire('pages')->get($repeaterItemId);
            if (!$repeaterItem->id) {
                throw new \Exception(__('Repeater item not found'));
            }
        }

        // Initialize agent
        $this->initAgent();

        if (!isset($this->agent)) {
            throw new \Exception(__('AI agent not initialized'));
        }

        // Process content with placeholder substitution
        $fullPrompt = $page
            ? PromptAIHelper::substituteAndPreparePrompt($promptText, $page, $content, $repeaterItem)
            : trim($promptText.PHP_EOL.$content);

        $filePath = null;
        $fileType = 'image';

        if ($imageBasename && $pageId && $imageFieldName) {
            if (!$page) {
                throw new \Exception(__('Page not found'));
            }
            $resolved = $this->resolveFile($page, $repeaterItem, $imageFieldName, $imageBasename);
            $filePath = $resolved['path'];
            $fileType = $resolved['type'];
        }

        return $this->buildMessage($fullPrompt, $filePath, $fileType);
    }

    /**
     * Look up a file by field name and basename/hash, resize images.
     *
     * @return array{path: string, type: string}
     */
    private function resolveFile(Page $page, ?Page $repeaterItem, string $imageFieldName, string $imageBasename): array {
        $fieldDef = wire('fields')->get($imageFieldName);
        if (!$fieldDef) {
            throw new \Exception(__('Field definition not found').': '.$imageFieldName);
        }

        $isImageField = ((string)$fieldDef->type === 'FieldtypeImage');
        $fileType = $isImageField ? 'image' : 'document';

        $fieldSource = $repeaterItem ?: $page;
        $imageField = $fieldSource->get($imageFieldName);
        if (!$imageField) {
            throw new \Exception(__('Field not found on page').': '.$imageFieldName);
        }

        // Find the specific file - try multiple approaches
        $file = $imageField->getFile($imageBasename);

        // If not found, try to find by hash
        if (!$file && strlen($imageBasename) === 32) {
            foreach ($imageField as $f) {
                if (strpos($f->basename, $imageBasename) !== false) {
                    $file = $f;
                    break;
                }
            }
        }

        if (!$file) {
            throw new \Exception(__('File not found').': '.$imageBasename.' in field '.$imageFieldName);
        }

        // Check if it's an image and resize if needed
        if ($fileType === 'image' && $file instanceof \ProcessWire\Pageimage) {
            $resized = $file->width(800);
            $filePath = $resized->filename;
        } else {
            $filePath = $file->filename;
        }

        return ['path' => $filePath, 'type' => $fileType];
    }

    /**
     * Create a UserMessage with optional file/image attachment.
     */
    private function buildMessage(string $prompt, ?string $filePath = null, string $fileType = 'image'): UserMessage {
        $message = new UserMessage($prompt);

        if ($filePath) {
            $fileContents = file_get_contents($filePath);
            $mimeType = PromptAIHelper::getMediaType($filePath);

            if ($fileContents) {
                if ($fileType === 'image') {
                    $supportedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

                    if (!in_array($mimeType, $supportedImageTypes)) {
                        throw new \Exception("Unsupported image type: {$mimeType}. Supported types: ".implode(', ', $supportedImageTypes));
                    }

                    $message->addAttachment(
                        new Image(base64_encode($fileContents), AttachmentContentType::BASE64, $mimeType)
                    );
                } else {
                    $supportedDocTypes = ['application/pdf', 'text/plain'];

                    if (!in_array($mimeType, $supportedDocTypes)) {
                        throw new \Exception("Unsupported document type: {$mimeType}. Supported types: ".implode(', ', $supportedDocTypes));
                    }

                    $message->addAttachment(
                        new Document(base64_encode($fileContents), AttachmentContentType::BASE64, $mimeType)
                    );
                }
            }
        }

        return $message;
    }

    public function init() {
        if ($this->initSettings()) {
            // Initialize both handlers since mode is per-prompt
            $inlineHandler = new PromptAIInlineMode($this);
            $inlineHandler->init();

            $pageHandler = new PromptAIPageMode($this);
            $pageHandler->init();
        }

        parent::init();
    }

    public function initSettings(): bool {
        // Set (user-)settings
        $this->provider = $this->get('provider');
        $this->model = $this->get('model');
        $this->apiKey = $this->get('apiKey');
        $this->systemPrompt = $this->get('systemPrompt');
        $this->promptMatrixString = $this->get('promptMatrix');
        $this->throttleSave = 5;

        return ($this->apiKey !== '' && $this->provider !== '' && $this->model !== '');
    }

    public function initAgent(): void {
        $this->agent = PromptAIAgent::make([
                                               'apiKey' => $this->apiKey,
                                               'provider' => $this->provider,
                                               'model' => $this->model,
                                               'systemPrompt' => $this->systemPrompt,
                                               'enableTools' => (bool)$this->get('enableTools'),
                                           ]);
    }

    public function chat(string $prompt, bool $returnText = true, ?string $file = null, ?string $fileType = 'image'): string|AssistantMessage|Message {
        if (!$prompt) {
            return '';
        }

        try {
            $message = $this->buildMessage($prompt, $file, $fileType);
            $response = $this->agent->chat($message);
        } catch (NeuronException $e) {
            $this->error($e->getMessage());

            return '';
        }

        try {
            $result = $response->getContent();
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return '';
        }

        if (isset($result->error)) {
            $this->error($result->error->message);

            return '';
        }

        return $returnText ? $result : $response;
    }

    public function testConnection(): string {
        $this->initAgent();

        if (!isset($this->agent)) {
            return __('No API key set');
        }

        $request = __('This is a test for the AI. Do you hear me?');
        $response = $this->chat($request, false);

        return json_encode([
                               'request' => $request,
                               'response' => $response,
                           ], JSON_PRETTY_PRINT);
    }

    public function ___fieldValueToString($value, ?Fieldtype $fieldtype): string {
        if (!$fieldtype) {
            return (string)$value;
        }

        if (in_array(get_class($fieldtype), PromptAIHelper::$textFieldTypes)) {
            return (string)$value;
        }

        if (in_array(get_class($fieldtype), PromptAIHelper::$fileFieldTypes)) {
            return (string)$value->description ?: '';
        }

        // Simple integer types
        if (is_numeric($value)) {
            return (string)$value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }
}
