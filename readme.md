# PromptAI

PromptAI is a ProcessWire module that integrates AI providers into the page editor. It processes text, image, and file fields through AI — either inline (per-field buttons) or on page save. The module supports regular fields, repeater fields, and repeater matrix fields. For image and file fields, the AI can analyze content and write to any subfield (description, alt text, captions, etc.). Prompts can reference other field values using placeholder syntax for context-aware processing.

## Features

### Multi-Provider Support

Choose from four AI providers:

- **Anthropic** (Claude)
- **OpenAI** (GPT)
- **Google Gemini**
- **DeepSeek** (text fields only — does not support image or file field processing)

### Two Processing Modes

- **Page Mode**: Add a "Save + Send to AI" button to the page editor. All configured prompts run on save.
- **Inline Mode**: AI buttons appear directly below individual fields. Process fields on-demand without saving the page.

Both modes can be mixed freely in the same configuration.

### Placeholder Support

Reference field values from anywhere on the page using `{page.fieldname}` syntax. When processing repeater items, access the current item's fields with `{item.fieldname}`.

```
Prompt: "Summarize the following text to 400 characters: {page.body}"
Field: summary
→ Creates a concise summary of the body field content
```

```
Prompt: "Create an SEO meta description for a page titled '{page.title}' about: {page.headline}"
Field: seo_description
→ Generates contextual SEO description using page title and headline
```

```
Prompt: "Generate alt text for this image based on the page topic: {page.category}"
Field: images (alt_text subfield)
→ Creates relevant alt text considering the page's category
```

```
Prompt: "Create gallery item description for '{item.caption}' from the {page.gallery_title} collection"
Field: description (within repeater)
→ Uses parent page gallery title and item caption for context-aware descriptions
```

### Custom Subfield Support

For image and file fields, the AI can write to any subfield. By default, the module writes to the `description` subfield. The "Target Subfield" option appears in the prompt configuration when file or image fields are selected. In inline mode, AI buttons appear next to all text subfield inputs automatically. See the [ProcessWire documentation on custom subfields](https://processwire.com/blog/posts/pw-3.0.142/) for more information.

### AI Tools (experimental)

The module ships with three example tools that allow the AI to query your ProcessWire installation:

- **getPages**: Find pages by selector
- **getPage**: Get detailed information about a single page
- **getFields**: List available templates and their fields

These tools are meant as starting points, not as fully usable fit-for-all-needs solutions. Their usefulness depends on how well you craft your prompts — the AI needs explicit instructions and context (via placeholders) to form meaningful queries. You may need to adapt or extend them for your specific use case. New tools can be added by dropping a `*Tool.php` file into the `tools/` directory.

Tools are disabled by default and must be explicitly enabled in module configuration. Security constraints apply: selector sanitization, no `include=all`, only text fields exposed, admin pages excluded.

## Supported Fields

### Text Fields
- PageTitle / PageTitleLanguage
- Text / TextLanguage
- Textarea / TextareaLanguage

### File & Image Fields
- Pageimage(s) — any text subfield
- Pagefile(s) — PDF, plain text, other formats might be possible depending on the AI provider

### Repeater Support
- Repeater fields
- Repeater Matrix fields
- All supported field types work within repeaters
- Each repeater item is processed individually

## Installation

1. Download and place in `/site/modules/PromptAI/`.
2. Activate the module in the ProcessWire admin.
3. Configure your API key and provider in Modules > Site > PromptAI.
4. Set up your prompts at Setup > Prompt AI.

## Permissions

This module installs the following permissions:
- promptai: Allows to use predefined prompts
- promptai-config: Allows to configure prompts

Superusers have all permissions by default.

## Configuration

### Basic Settings

Configure in Modules > Site > PromptAI:

- **AI Provider** (required): Anthropic, OpenAI, Gemini, or DeepSeek
- **AI Model** (required): The model to use (see provider documentation for available models)
- **API Key** (required): Your API key for the selected provider
- **System Prompt** (optional): A general instruction sent to the AI with every request
- **Individual Prompt Buttons** (optional): Show separate buttons per prompt instead of one combined button when using page mode
- **Enable AI Tools** (optional, experimental): Allow the AI to call ProcessWire API tools for data retrieval
- **Test Settings**: Send a test request to verify your configuration

### Prompt Configuration

Navigate to **Setup > Prompt AI** to configure prompts:

Each prompt consists of:

- **Label**: Identifier for the button and configuration overview
- **Mode**: Page Mode (process on save) or Inline Mode (process on-demand)
- **Template(s)**: Which templates this prompt applies to. Leave empty for all templates. Select repeater templates (labeled "Repeater: fieldname") to process repeater fields.
- **Field(s)**: The field(s) to process
- **Target Subfield**: For file/image fields — which subfield to write results to (default: `description`)
- **Overwrite Field Content**: Whether AI responses overwrite existing content (page mode only, disabled by default)
- **Ignore Field Content**: Send only the prompt without the field's current text content. Useful for prompts that generate content from placeholders alone. Files and images are still sent.
- **Prompt**: Instructions for the AI, with optional `{page.fieldname}` / `{item.fieldname}` placeholders

Each prompt can be duplicated, reordered (move up/down), or removed using the icons in the prompt header.

### Button Modes

**Single Button (default):**
One "Save + Send to AI" button that processes all applicable prompts.

**Individual Buttons:**
Enable "Individual Prompt Buttons" in module configuration. Each prompt gets its own button labeled with the prompt's Label field.

### Content Overwrite Protection

Per-prompt setting that controls how existing content is handled:

- **Disabled (default)**: Only writes to empty fields, preserving existing content
- **Enabled**: Always overwrites existing content

This setting only applies in page mode. In inline mode, responses always replace the field content, but the page is not automatically saved after processing.

> **Note:**
> - Text fields: Field content is sent to AI with the prompt. The result replaces the field content.
> - Image & file fields: Each file/image is sent individually. Results are written to the configured Target Subfield.
> - Repeater items are processed individually with the same prompt.
> - There is a 5-second throttle between AI requests to prevent accidental multi-processing.

## Configuration Examples

### Regular Page Fields

**Text field processing:**
- Template: `basic-page` | Field: `body`
- Prompt: `Add an emoji at the beginning of this text`
- Result: The `body` field content is sent to AI and updated with the result

**Image descriptions:**
- Template: `basic-page` | Field: `images`
- Prompt: `Create a short alt-text for this image`
- Result: Each image is analyzed, result saved to the image's description

**Multiple fields:**
- Template: `blog-post` | Fields: `headline`, `summary`
- Prompt: `Make this text more engaging and add an emoji`
- Result: Both fields are processed with the same prompt

### Repeater Fields

**Repeater text fields:**
- Template: `Repeater: gallery` | Field: `caption`
- Prompt: `Rewrite this caption to be more descriptive`
- Result: Each repeater item's caption is processed individually

**Repeater image fields:**
- Template: `Repeater: portfolio_items` | Field: `project_image`
- Prompt: `Describe this portfolio image professionally`
- Result: Each repeater item's images are analyzed individually

### Placeholders

**Page context for generation:**
- Template: `blog-post` | Field: `summary`
- Prompt: `Create a compelling summary for an article titled "{page.title}" about: {page.body}`

**Repeater context with parent page fields:**
- Template: `Repeater: gallery_items` | Field: `caption`
- Prompt: `Write a caption for this gallery item titled "{item.title}" from the {page.gallery_name} collection`

### Extending Placeholder Support

By default, placeholders work with simple field types (text, numbers, booleans). For complex field types like PageArray (page references), extend the system using the hookable `PromptAI::fieldValueToString` method.

Add this (or your custom solution) to your `/site/ready.php`:

```php
<?php namespace ProcessWire;

wire()->addHookBefore('PromptAI::fieldValueToString', function($event) {
    if (get_class($event->arguments(0)) === 'ProcessWire\PageArray') {
        /** @var PageArray $pages */
        $pages = $event->arguments(0);
        $return = $pages->implode(', ', 'title');
        $event->replace = true;
        $event->return = $return;
    }
});
```

With this hook, a prompt like `Create content for a product in these categories: {page.categories}` will resolve to "Electronics, Computers, Accessories".

You can add similar hooks for Options fields, Datetime fields, or other custom Fieldtypes.

## Running Tests

Tests use Pest PHP and are separate from production dependencies. To set up:

```bash
cd tests
composer install
```

To run:

```bash
ddev php site/modules/PromptAI/tests/vendor/bin/pest --configuration=site/modules/PromptAI/tests/phpunit.xml
```

## Requirements

- ProcessWire >= 3.0.184
- PHP >= 8.3
