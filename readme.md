# PromptAI

PromptAI is a Processwire CMS module that utilizes AI to process text fields upon saving. The processed text can be saved back to the original field or a different one on the same page. For image fields, the AI can write image descriptions or populate custom image subfields.

## Field support

- PageTitle(Language)
- Text(Language)
- Textarea(Language)
- Pageimage(s), including custom fields

## Installation

1. Download and install [PromptAI](https://github.com/robertweiss/PromptAI).
2. Configure the module settings.
3. Open a page, click on the arrow next to the save-button, and select ›Save + send to AI‹.

## Settings

- AI Provider (required): Choose from Anthropic, OpenAI, or Gemini.
- AI Model (required): Use the links provided below the field to select the best model for your needs.
- API Key (required): Obtain an API key using the provided links.
- System prompt (optional): A general instruction sent to the AI with every request.
- Prompts (required): Configure prompts as detailed below.
- Test Settings (optional): Send a test request to the AI provider when saving module configurations.

## Prompts Configuration

Define the template, source field, target field, and prompt for each AI interaction. Multiple calls can be set by adding new lines. Separate template, source, target, and prompt with a double colon (see examples below).

- Template: If not specified, the prompt applies to all templates.
- Source Field: The field content sent to the AI.
- Target Field: Where the result is saved. Defaults to the source field if not specified.
- Prompt: Prefixed to the source field; add instructions like ›write a summary with max. 400 characters of the following text‹

> [!NOTE]
> If an image field is the source, the target is treated as a custom subfield (See https://processwire.com/blog/posts/pw-3.0.142/ for infos about image custom fields). If left empty, "description" is the default target.

### Supported field combinations / Examples:

1. **Source text field → Target text field:** Overwrites target field with the result.  
Example: `basic-page::copy::copy2::Create a summary of the following text`
2. **Source text field → No target field:** Overwrites source field with the result.  
Example: `basic-page::copy::::Add an emoji to the following text`
3. **Source image field → No target field:** Sends each image to the AI; results are saved in the image description.  
Example: `basic-page::images::::Create a short alt-text for this image`
4. **Source image field → Target subfield:** Sends each image to the AI; results are saved in the specified custom field.  
Example: `basic-page::images::alt_text::Create a short alt-text for this image`

**Note:** This is a beta release. While it performs well in production, please test thoroughly before deploying. Report any bugs via GitHub issues to help me improve.
