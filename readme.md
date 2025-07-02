# PromptAI

PromptAI is a ProcessWire CMS module that utilizes AI to process text, image, and file fields upon saving. The processed text can be saved back to the original field or a different one on the same page. The module supports regular page fields, repeater fields, and repeater matrix fields. For image and file fields, the AI can analyze content and write descriptions or populate custom subfields.

## Field support

### Regular Fields
- PageTitle(Language)
- Text(Language)
- Textarea(Language)
- Pageimage(s), including custom fields
- Pagefile(s) (PDF, RTF, Markdown, JSON, XML, CSV, TXT), including custom fields

### Repeater Support
- **Repeater fields**: Process fields within repeater items
- **Repeater Matrix fields**: Process fields within repeater matrix items
- All supported field types work within repeaters
- Each repeater item is processed individually

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
- **Individual Prompt Buttons** (optional): Show separate "Send to AI" buttons for each prompt configuration instead of one general button
- **Overwrite Target Field Content** (optional): Controls whether AI responses overwrite existing content in target fields (disabled by default)
- **Test Settings** (optional): Send a test request to verify your configuration

### Prompt Configuration

Navigate to **Setup > Prompt AI** to configure your AI prompts using the visual form interface:

#### Configuration Fields

Each prompt configuration consists of:

- **Label**: Optional identifier for easy recognition
- **Template**: The template this prompt applies to (leave empty for all templates, or select a repeater template to process repeater fields)
- **Source Field**: The field whose content is sent to the AI
- **Target Field**: Where the AI result is saved (leave empty to overwrite the source field)
- **Prompt**: Instructions for the AI (prefixed to the source field content)

#### Managing Configurations

- **Add**: Click "Add New Prompt Configuration" to create a new prompt
- **Remove**: Click the trash icon to delete individual configurations

#### Button Behavior

PromptAI offers two button modes when editing pages:

**Single Button Mode (default):**
- Shows one "Save + Send to AI" button
- Processes all applicable prompt configurations when clicked

**Individual Button Mode:**
- Enable "Individual Prompt Buttons" in module configuration
- Shows separate buttons for each prompt configuration
- Button labels use the prompt's "Label" field (falls back to "Send to AI")
- Only the selected prompt configuration is processed when clicked
- Useful for selective AI processing and better user control

#### Content Overwrite Protection

The **"Overwrite Target Field Content"** setting controls how the module handles existing content:

- **Disabled (default)**: AI responses are only written to empty target fields/subfields, preserving existing content
- **Enabled**: AI responses always overwrite existing content in target fields/subfields

> [!NOTE]
> - **Image & File fields**: Both work identically - the target field is treated as a custom subfield of the file/image (See https://processwire.com/blog/posts/pw-3.0.142/ for info about custom fields). If target is left empty, "description" is the default subfield.
> - **Supported file formats**: PDF, RTF, Markdown (.md), JSON, XML, CSV, and plain text files.
> - **File/Image processing**: Each file or image in the field is processed individually with the same prompt.
> - **Repeater support**: Templates are automatically detected and labeled as "Repeater: fieldname" in the template dropdown.
> - **Repeater processing**: Each repeater item is processed individually with the same prompt.
> - **Compatibility**: The module supports both regular Repeater fields and Repeater Matrix fields.

### Supported field combinations / Examples:

#### Regular Page Fields

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

5. **Source file field → Target subfield:** Sends each file to the AI; results are saved in the specified custom subfield.  
   - Template: `basic-page`
   - Source Field: `documents`
   - Target Field: `summary`
   - Prompt: `Summarize the key points from this document`

6. **Source file field → No target field:** Sends each file to the AI; results are saved in the file description.  
   - Template: `basic-page`
   - Source Field: `attachments`
   - Target Field: (empty)
   - Prompt: `Create a brief description of this document`

#### Repeater Fields

7. **Repeater text field processing:** Process text fields within repeater items.  
   - Template: `Repeater: gallery`
   - Source Field: `title`
   - Target Field: `description`
   - Prompt: `Create a compelling description based on this title`

8. **Repeater image field processing:** Process image fields within repeater items.  
   - Template: `Repeater: portfolio_items`
   - Source Field: `project_image`
   - Target Field: (empty - uses description)
   - Prompt: `Describe this portfolio image professionally`

9. **Repeater file field processing:** Process file fields within repeater items.  
   - Template: `Repeater: resources`
   - Source Field: `document`
   - Target Field: `summary` (custom subfield)
   - Prompt: `Extract the main topics from this document`

10. **Repeater Matrix field processing:** Process fields within repeater matrix items.  
    - Template: `Repeater: content_blocks`
    - Source Field: `heading`
    - Target Field: `subheading`
    - Prompt: `Create a catchy subheading for this section`

**Note:** This is a beta release. While it performs well in production, please test thoroughly before deploying. Report any bugs via GitHub issues to help improve the module.
