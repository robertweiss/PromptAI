# PromptAI

PromptAI is a ProcessWire CMS module that utilizes AI to process text fields upon saving. The processed text can be saved back to the original field or a different one on the same page. For image fields, the AI can write image descriptions or populate custom image subfields.

## Field support

- PageTitle(Language)
- Text(Language)
- Textarea(Language)
- Pageimage(s), including custom fields

## Installation

1. Download and install [PromptAI](https://github.com/robertweiss/PromptAI).
2. Configure the module through the dedicated "Prompt AI" page in the admin.
3. Open a page, click on the arrow next to the save-button, and select "Save + send to AI".

## Configuration

PromptAI creates a dedicated configuration page accessible from **Setup > Prompt AI** in the ProcessWire admin interface.

### Basic Settings

Configure these settings in the module configuration (Modules > Site > PromptAI > Configure):

- **AI Provider** (required): Choose from Anthropic, OpenAI, or Gemini
- **AI Model** (required): Specify the model to use (see provider documentation for available models)
- **API Key** (required): Your API key for the selected provider
- **System Prompt** (optional): A general instruction sent to the AI with every request
- **Test Settings** (optional): Send a test request to verify your configuration

### Prompt Configuration

Navigate to **Setup > Prompt AI** to configure your AI prompts using the visual form interface:

#### Configuration Fields

Each prompt configuration consists of:

- **Label**: Optional identifier for easy recognition
- **Template**: The template this prompt applies to (leave empty for all templates)
- **Source Field**: The field whose content is sent to the AI
- **Target Field**: Where the AI result is saved (leave empty to overwrite the source field)
- **Prompt**: Instructions for the AI (prefixed to the source field content)

#### Managing Configurations

- **Add**: Click "Add New Prompt Configuration" to create a new prompt
- **Remove**: Click the trash icon to delete individual configurations
- **Clean State**: You can remove all configurations to start fresh

> [!NOTE]
> If an image field is the source, the target is treated as a custom subfield (See https://processwire.com/blog/posts/pw-3.0.142/ for info about image custom fields). If left empty, "description" is the default target.

### Supported field combinations / Examples:

1. **Source text field → Target text field:** Overwrites target field with the result.  
   - Template: `basic-page`
   - Source Field: `copy`
   - Target Field: `copy2`
   - Prompt: `Create a summary of the following text`

2. **Source text field → No target field:** Overwrites source field with the result.  
   - Template: `basic-page`
   - Source Field: `copy`
   - Target Field: (empty)
   - Prompt: `Add an emoji to the following text`

3. **Source image field → No target field:** Sends each image to the AI; results are saved in the image description.  
   - Template: `basic-page`
   - Source Field: `images`
   - Target Field: (empty)
   - Prompt: `Create a short alt-text for this image`

4. **Source image field → Target subfield:** Sends each image to the AI; results are saved in the specified custom field.  
   - Template: `basic-page`
   - Source Field: `images`
   - Target Field: `alt_text`
   - Prompt: `Create a short alt-text for this image`

**Note:** This is a beta release. While it performs well in production, please test thoroughly before deploying. Report any bugs via GitHub issues to help improve the module.