# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with the PromptAI module.

## Module Overview

PromptAI is a ProcessWire module that integrates multiple AI providers (Anthropic, OpenAI, Gemini, DeepSeek) to process page content. It adds a "Save + Send to AI" button to the page editor that processes configured field mappings through AI.

**Version**: 17
**Dependencies**: `inspector-apm/neuron-ai` ^1.14
**Git Repository**: This module is a separate git repository within the ProcessWire installation

## Architecture

### Core Components

The module is split across 5 main files:

1. **PromptAI.module.php**: Main module class
   - Extends `Process implements Module`
   - Hooks into `ProcessPageEdit::getSubmitActions` to add dropdown button
   - Hooks into `Pages::saved` to process AI requests
   - Handles field processing logic for text and file fields

2. **PromptAIAgent.php**: AI provider wrapper
   - Extends `NeuronAI\Agent`
   - Factory pattern for multi-provider support
   - Supports: Anthropic, OpenAI, Gemini, DeepSeek
   - Handles provider initialization via `provider()` method

3. **PromptAIConfigForm.php**: Visual configuration interface
   - Renders Alpine.js-powered dynamic form
   - Manages JSON configuration in browser before saving
   - Handles asmSelect integration for multi-template selection

4. **PromptAIHelper.php**: Static utility class
   - Configuration parsing and validation
   - Version migration handlers (v12, v15, v16)
   - Template/field matching logic
   - Repeater field detection

5. **PromptAI.config.php**: Module configuration
   - Defines `PromptMatrixEntity` data class
   - Extends `ModuleConfig` for settings interface
   - Test connection functionality

### Configuration Data Flow

**Storage**: JSON string in module config (`promptMatrix` field)

**Structure**:
```json
[
  {
    "template": [1, 2, 3],        // Array of template IDs or null (all)
    "sourceField": 123,            // Field ID (required)
    "targetField": 456,            // Field ID or null (uses source)
    "prompt": "...",               // AI instruction (required)
    "label": "...",                // UI label (optional)
    "overwriteTarget": false       // Boolean (v16+)
  }
]
```

**Evolution**:
- **v12**: Migrated from line-based format (`template::source::target::prompt::label`) to JSON
- **v15**: Migrated `template` from single integer to array
- **v16**: Migrated `overwriteTarget` from global to per-prompt setting

### Field Processing Flow

1. **User clicks "Save + Send to AI"** → Sets `_after_submit_action` POST var
2. **`hookPageSave()`** triggered → Checks throttle limit (5s)
3. **`processPrompts()`** or **`processSpecificPrompt()`** → Filters by template
4. **`processFields()`** → Handles repeaters vs regular fields
5. **`processTextField()`** or **`processFileField()`** → Type-specific logic
6. **`chat()`** → Sends to AI via NeuronAI
7. **Save result** → Uses `setAndSave()` with `noHook` flag to prevent recursion

### Repeater Field Handling

**Detection**: Template names starting with `repeater_`

**Processing**:
- Extract field name: `repeater_gallery` → `gallery`
- Get repeater items: `$page->gallery`
- Process each item: `processRepeaterItem($item, $promptMatrixEntity)`
- Items are separate `Page` objects with their own template

**Template matching**: A prompt with `template: [repeater_gallery_id]` only processes that repeater's fields

### File vs Image Field Processing

**Common logic**:
- Both use `FieldtypeImage` or `FieldtypeFile`
- Process each file in the field individually
- Target field is interpreted as subfield name (e.g., `description`, `alt_text`)
- Default subfield: `description`

**Image-specific**:
- Resizes to 800px width before sending: `$file->width(800)->filename`
- MIME validation: jpeg, png, gif, webp
- Attachment type: `AttachmentContentType::BASE64`

**Document-specific**:
- Supported: PDF, TXT, CSV, RTF, Markdown, JSON, XML
- MIME validation against `$supportedDocTypes` array
- Uses full file: `$file->filename`

**DeepSeek limitation**: File/image fields not supported (check at PromptAI.module.php:414)

## Alpine.js Configuration Interface

**JavaScript framework**: Alpine.js 3.x (bundled in `alpine.min.js`)

**Data model**:
```javascript
{
  fieldsets: [
    {
      template: [],           // Array of IDs
      sourceField: '',        // Single ID
      targetField: '',        // Single ID
      prompt: '',
      label: '',
      overwriteTarget: false
    }
  ]
}
```

**Key features**:
- `x-model` bindings sync form fields to JS data
- asmSelect integration for multi-select (requires manual init on add)
- Change listener on asmSelect updates Alpine data
- Smooth scroll to new fieldsets on add
- Hidden field `prompt_config_data` serializes JSON on submit

**Critical asmSelect integration** (PromptAIConfigForm.php:204-239):
- ProcessWire's `InputfieldAsmSelect` uses jQuery plugin
- Alpine doesn't auto-detect asmSelect changes
- Manual jQuery `.on('change.asmAlpine')` listener bridges gap
- Must call `setupListener()` after adding new fieldsets
- Must call `initInputfieldAsmSelect()` for new elements

## Translation Function Gotcha

ProcessWire's `__()` doesn't support interpolation. Variables must be concatenated outside:

```php
// ❌ Wrong
__('Source field with ID ' . $id . ' does not exist')

// ✅ Correct (PromptAI.module.php:331)
__('Source field with ID ') . $promptMatrixEntity->sourceField . __(' does not exist in template ') . $page->template->name
```

**Why it matters**: Translations extract literal strings. Interpolation breaks translation matching.

## Hook System

**Autoload mode**: `template=admin` (only loads in admin)

**Hooks added** (PromptAI.module.php:52-53):
1. `addHookAfter("ProcessPageEdit::getSubmitActions")` → Adds button(s)
2. `addHookAfter("Pages::saved")` → Processes AI request

**Button logic**:
- **Single button** (`individualButtons=0`): `save_and_chat` → processes all applicable prompts
- **Multiple buttons** (`individualButtons=1`): `save_and_chat_{index}` → processes specific prompt

**No-hook saves** (PromptAI.module.php:405):
```php
$page->setAndSave($target, $result, ['noHook' => true]);
```
Prevents infinite loop (save → hook → save → hook...)

## Field Type Whitelists

**Text fields** (PromptAIHelper.php:6-13):
- `FieldtypePageTitle`, `FieldtypePageTitleLanguage`
- `FieldtypeText`, `FieldtypeTextLanguage`
- `FieldtypeTextarea`, `FieldtypeTextareaLanguage`

**File fields** (PromptAIHelper.php:15-18):
- `FieldtypeImage`
- `FieldtypeFile`

**Excluded templates** (PromptAIHelper.php:4):
- `admin`, `language`, `user`, `permission`, `role`
- Templates starting with `field-`

## Common Development Tasks

### Adding new AI provider
1. Update `PromptAIAgent::provider()` with new case
2. Add provider to `PromptAIConfig::$providers` array
3. Install provider via composer in module root
4. Update provider docs links in config

### Changing configuration structure
1. Bump version in `PromptAI.info.php`
2. Add migration in `PromptAI.module.php::upgrade()`
3. Implement migration in `PromptAIHelper::migrate*()`
4. Test by downgrading version, changing config, upgrading

### Debugging AI requests
```php
// In chat() method, add before sending:
ray($prompt);  // See actual prompt sent
ray($response); // See full response object
```

### Testing field processing
1. Create test page with configured template
2. Add content to source field
3. Click dropdown → "Save + Send to AI"
4. Check throttle timing (5 seconds between saves)
5. Check target field for result

## Module Configuration Page

**Admin URL**: `Setup > Prompt AI` (`/pw/setup/prompt-ai/`)

**Separate from module config**: Module config (`Modules > PromptAI > Configure`) handles API keys/settings, Setup page handles prompt matrix

**Routing**: `PromptAI::___execute()` renders form, handles POST submissions

**Form submission**:
1. Alpine serializes fieldsets to JSON
2. Hidden field `prompt_config_data` contains JSON
3. `PromptAIConfigForm::processSubmission()` parses and validates
4. Saves to module config as JSON string
5. Redirects to same page

## Version Migrations

**When they run**: On module refresh/upgrade via `upgrade($fromVersion, $toVersion)`

**v12 migration** (PromptAIHelper.php:20-89):
- Old: `template::sourceField::targetField::prompt::label` (line-based)
- New: JSON array with IDs
- Converts field/template names to IDs

**v15 migration** (PromptAIHelper.php:91-122):
- Old: `"template": 123` (single integer)
- New: `"template": [123]` (array)
- Enables multi-template support

**v16 migration** (PromptAIHelper.php:124-157):
- Old: Global `overwriteTarget` setting
- New: Per-prompt `overwriteTarget` in JSON
- Copies global value to each prompt

## ProcessWire-Specific Patterns

**Module structure**:
- `*.module.php`: Main class implementing `Module`
- `*.info.php`: Metadata array (version, dependencies, etc.)
- `*.config.php`: Configuration interface extending `ModuleConfig`

**Field access**:
- Get by name: `$page->fieldname`
- Get by ID: `wire('fields')->get($id)`
- Check existence: `$page->get($fieldname) !== null`

**Template access**:
- By name: `wire('templates')->get('basic-page')`
- By ID: `wire('templates')->get(123)`
- Page's template: `$page->template`

**Module config**:
- Get: `wire('modules')->getConfig('PromptAI')`
- Save: `wire('modules')->saveConfig('PromptAI', $config)`

## Known Limitations

- DeepSeek doesn't support file/image fields (framework limitation)
- 5-second throttle between AI requests (prevents abuse)
- Repeater processing is sequential (not batched)
- File size limits depend on AI provider
- No streaming support (waits for complete response)
