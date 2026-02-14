/**
 * PromptAI Inline Mode - Event-Driven Approach
 *
 * This script handles AI processing triggered by native ProcessWire header action buttons.
 * Buttons are added server-side via renderReady hooks based on field selection configuration.
 */

(function() {
    'use strict';

    // Configuration - read from ProcessWire's config object
    const pwConfig = typeof ProcessWire !== 'undefined' && ProcessWire.config && ProcessWire.config.PromptAIInlineMode
        ? ProcessWire.config.PromptAIInlineMode
        : {};

    const config = {
        prompts: pwConfig.prompts || {},
        ajaxUrl: pwConfig.ajaxUrl || '',
        streamUrl: pwConfig.streamUrl || '',
        pageId: pwConfig.pageId || 0,
        useNativeButtons: pwConfig.useNativeButtons || false
    };

    /**
     * Show loading state on field
     */
    function showLoading(field, inputfield) {
        field.disabled = true;
        field.style.opacity = '0.6';
        inputfield.classList.add('promptai-processing');

        // Update button icon to spinner
        const button = inputfield.querySelector('.InputfieldHeaderAction-promptai .fa');
        if (button) {
            button.className = 'fa fa-spinner fa-spin';
        }
    }

    /**
     * Hide loading state
     */
    function hideLoading(field, inputfield) {
        field.disabled = false;
        field.style.opacity = '1';
        inputfield.classList.remove('promptai-processing');

        // Restore button icon
        const button = inputfield.querySelector('.InputfieldHeaderAction-promptai .fa');
        if (button) {
            button.className = 'fa fa-magic';
        }
    }

    /**
     * Show error notification
     */
    function showError(message, field) {
        const notification = document.createElement('div');
        notification.className = 'uk-alert uk-alert-danger';
        notification.style.cssText = 'margin-top: 5px;';
        notification.innerHTML = `<p><i class="fa fa-exclamation-circle"></i> ${message}</p>`;

        const wrapper = field.closest('.Inputfield');
        if (wrapper) {
            wrapper.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 5000);
        }
    }

    /**
     * Process field with AI
     */
    function processField(field, prompt, inputfield) {

        // If field is a TinyMCE wrapper, find the actual textarea
        let actualField = field;
        if (field.classList && (field.classList.contains('mce-tinymce') || field.classList.contains('tox-tinymce'))) {
            // This is a TinyMCE wrapper, find the original textarea
            actualField = inputfield.querySelector('textarea.InputfieldTinyMCEEditor');
            if (!actualField) return;
        }

        // Get content from field - check if it's TinyMCE
        let content = actualField.value;
        const isTinyMCE = actualField.classList.contains('InputfieldTinyMCEEditor');

        if (isTinyMCE && typeof tinymce !== 'undefined' && actualField.id) {
            const editor = tinymce.get(actualField.id);
            if (editor) {
                content = editor.getContent();
            }
        }

        showLoading(actualField, inputfield);

        // Detect if this is an image/file field description
        // For repeater fields, use the data attribute page ID from the Inputfield wrapper
        const pageIdAttr = inputfield.getAttribute('data-promptai-page-id');
        let pageId = pageIdAttr ? parseInt(pageIdAttr, 10) : config.pageId;
        let imageFieldName = null;
        let imageBasename = null;
        let repeaterItemId = null;
        let subfieldName = null;

        // Parse field name to detect image/file fields
        // Pattern: {subfield}{langid?}_{fieldname}{_repeaterXXXX?}_{hash}
        // Hash is always 32-char hex at the end.
        // Subfield names can contain underscores (alt_text, custom_field).
        // Strategy: match hash, then extract fieldname as last segment before hash.
        const hashMatch = actualField.name.match(/^(.+)_([a-f0-9]{32})$/);
        const fieldNameMatch = hashMatch ? true : false;

        if (fieldNameMatch) {
            const prefix = hashMatch[1]; // Everything before the hash
            const hash = hashMatch[2];
            let fullFieldName;

            // Check for repeater suffix: ..._fieldname_repeaterXXXX
            const repeaterSuffixMatch = prefix.match(/_repeater(\d+)$/);

            if (repeaterSuffixMatch) {
                // Remove _repeaterXXXX to get ..._fieldname
                const beforeRepeater = prefix.substring(0, prefix.length - repeaterSuffixMatch[0].length);
                const lastUnderscore = beforeRepeater.lastIndexOf('_');
                if (lastUnderscore !== -1) {
                    fullFieldName = beforeRepeater.substring(lastUnderscore + 1);
                    const beforeField = beforeRepeater.substring(0, lastUnderscore);
                    subfieldName = beforeField.replace(/\d+$/, ''); // strip lang suffix
                } else {
                    fullFieldName = beforeRepeater;
                }
                // Re-compose fullFieldName with repeater suffix for repeater detection below
                fullFieldName = fullFieldName + repeaterSuffixMatch[0];
            } else {
                // No repeater: last underscore-separated segment is fieldname
                const lastUnderscorePos = prefix.lastIndexOf('_');
                if (lastUnderscorePos !== -1) {
                    fullFieldName = prefix.substring(lastUnderscorePos + 1);
                    const beforeField = prefix.substring(0, lastUnderscorePos);
                    subfieldName = beforeField.replace(/\d+$/, ''); // strip lang suffix
                } else {
                    fullFieldName = prefix;
                }
            }

            // Extract actual field name and repeater item ID (remove _repeaterXXXX suffix)
            const repeaterMatch = fullFieldName.match(/^(.+?)_repeater(\d+)$/);
            if (repeaterMatch) {
                imageFieldName = repeaterMatch[1]; // e.g., "images_repeater1042" -> "images"
                repeaterItemId = parseInt(repeaterMatch[2], 10); // Extract repeater item page ID
            } else {
                imageFieldName = fullFieldName;
            }

            // Find the actual file to get the full filename with extension
            const fileItem = document.getElementById('file_' + hash);

            if (fileItem) {
                // Try to find filename from various sources
                // 1. Check for img with data-original (images)
                const img = fileItem.querySelector('img[data-original]');
                if (img) {
                    const originalSrc = img.dataset.original;
                    const urlParts = originalSrc.split('/');
                    const filename = urlParts[urlParts.length - 1];
                    imageBasename = filename.split('?')[0];
                } else {
                    // 2. Check for link with filename (files)
                    const link = fileItem.querySelector('a[href*="/site/assets/files/"]');
                    if (link) {
                        const href = link.getAttribute('href');
                        const urlParts = href.split('/');
                        const filename = urlParts[urlParts.length - 1];
                        imageBasename = filename.split('?')[0];
                    } else {
                        // 3. Try to find any element with the filename as text
                        const filenameSpan = fileItem.querySelector('.InputfieldFileName, .pw-edit-file-name');
                        if (filenameSpan) {
                            imageBasename = filenameSpan.textContent.trim();
                        } else {
                            // 4. Last resort: check data attributes
                            const dataBasename = fileItem.getAttribute('data-basename');
                            if (dataBasename) {
                                imageBasename = dataBasename;
                            } else {
                                imageBasename = hash;
                            }
                        }
                    }
                }
            } else {
                imageBasename = hash;
            }
        } else {
            // Not a file field - check if it's a text field in a repeater
            // Pattern: fieldname_repeaterID or fieldname_langID_repeaterID
            const textRepeaterMatch = actualField.name.match(/^(.+?)_repeater(\d+)$/);
            if (textRepeaterMatch) {
                repeaterItemId = parseInt(textRepeaterMatch[2], 10);
            }
        }

        const requestData = {
            content: content,
            prompt: prompt.prompt,
            page_id: pageId // Always send page_id for placeholder substitution
        };

        // Add repeater item ID if detected
        if (repeaterItemId) {
            requestData.repeater_item_id = repeaterItemId;
        }

        // Add image/file-specific data if detected
        if (imageFieldName && imageBasename) {
            requestData.image_field = imageFieldName;
            requestData.image_basename = imageBasename;
            if (subfieldName) {
                requestData.target_subfield = subfieldName;
            }
        }

        if (config.streamUrl) {
            processFieldStreaming(actualField, inputfield, isTinyMCE, requestData);
        } else {
            processFieldNonStreaming(actualField, inputfield, isTinyMCE, requestData);
        }
    }

    /**
     * Process field via SSE streaming (fetch + ReadableStream)
     */
    function processFieldStreaming(actualField, inputfield, isTinyMCE, requestData) {
        const isCKEditor = actualField.classList.contains('InputfieldCKEditorNormal') && typeof CKEDITOR !== 'undefined' && actualField.id;
        const isRichEditor = isTinyMCE || isCKEditor;
        let accumulatedText = '';
        let throttleTimer = null;
        const THROTTLE_MS = 150;

        function scrollIframeDocToBottom(doc) {
            try {
                if (doc) {
                    doc.body.scrollTop = doc.body.scrollHeight;
                    doc.documentElement.scrollTop = doc.documentElement.scrollHeight;
                }
            } catch (e) {}
        }

        function updateField(text, isFinal) {
            if (isTinyMCE && typeof tinymce !== 'undefined' && actualField.id) {
                const editor = tinymce.get(actualField.id);
                if (editor) {
                    editor.setContent(text);
                    if (isFinal) editor.fire('change');
                    setTimeout(function() {
                        scrollIframeDocToBottom(editor.getDoc());
                    }, 10);
                } else {
                    actualField.value = text;
                    actualField.scrollTop = actualField.scrollHeight;
                }
            } else if (isCKEditor) {
                const editor = CKEDITOR.instances[actualField.id];
                if (editor) {
                    editor.setData(text);
                    editor.once('dataReady', function() {
                        scrollIframeDocToBottom(editor.document && editor.document.$);
                    });
                }
            } else {
                actualField.value = text;
                actualField.scrollTop = actualField.scrollHeight;
            }
        }

        function scheduleUpdate() {
            if (isRichEditor) {
                if (!throttleTimer) {
                    throttleTimer = setTimeout(function() {
                        throttleTimer = null;
                        updateField(accumulatedText, false);
                    }, THROTTLE_MS);
                }
            } else {
                updateField(accumulatedText, false);
            }
        }

        fetch(config.streamUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(requestData)
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            function processStream() {
                return reader.read().then(function(result) {
                    if (result.done) {
                        // Stream finished — do final update
                        if (throttleTimer) {
                            clearTimeout(throttleTimer);
                            throttleTimer = null;
                        }
                        if (accumulatedText) {
                            updateField(accumulatedText, true);
                        }
                        hideLoading(actualField, inputfield);
                        actualField.dispatchEvent(new Event('change', { bubbles: true }));
                        actualField.dispatchEvent(new Event('input', { bubbles: true }));
                        return;
                    }

                    buffer += decoder.decode(result.value, { stream: true });

                    // Parse SSE events from buffer
                    var parts = buffer.split('\n\n');
                    // Last part may be incomplete
                    buffer = parts.pop();

                    for (var i = 0; i < parts.length; i++) {
                        var block = parts[i].trim();
                        if (!block) continue;

                        var eventType = 'message';
                        var data = '';
                        var lines = block.split('\n');

                        for (var j = 0; j < lines.length; j++) {
                            var line = lines[j];
                            if (line.startsWith('event: ')) {
                                eventType = line.substring(7).trim();
                            } else if (line.startsWith('data: ')) {
                                data = line.substring(6);
                            } else if (line.startsWith('data:')) {
                                data = line.substring(5);
                            }
                            // Skip comments (: ping, : keep-alive) and padding
                        }

                        if (eventType === 'chunk' && data) {
                            try {
                                var text = JSON.parse(data);
                                accumulatedText += text;
                                scheduleUpdate();
                            } catch (e) {
                                // Skip malformed chunks
                            }
                        } else if (eventType === 'error' && data) {
                            try {
                                var errorMsg = JSON.parse(data);
                                showError(errorMsg, actualField);
                            } catch (e) {
                                showError(data, actualField);
                            }
                            hideLoading(actualField, inputfield);
                            return;
                        } else if (eventType === 'close') {
                            // Server signals end — final update
                            if (throttleTimer) {
                                clearTimeout(throttleTimer);
                                throttleTimer = null;
                            }
                            if (accumulatedText) {
                                updateField(accumulatedText, true);
                            }
                            hideLoading(actualField, inputfield);
                            actualField.dispatchEvent(new Event('change', { bubbles: true }));
                            actualField.dispatchEvent(new Event('input', { bubbles: true }));
                            return;
                        }
                    }

                    return processStream();
                });
            }

            return processStream();
        })
        .catch(function(error) {
            if (throttleTimer) {
                clearTimeout(throttleTimer);
                throttleTimer = null;
            }
            hideLoading(actualField, inputfield);
            showError('Network error: ' + error.message, actualField);
        });
    }

    /**
     * Process field via non-streaming JSON fetch (fallback)
     */
    function processFieldNonStreaming(actualField, inputfield, isTinyMCE, requestData) {
        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(requestData)
        })
        .then(response => response.json())
        .then(data => {
            hideLoading(actualField, inputfield);

            if (data.success) {
                if (isTinyMCE && typeof tinymce !== 'undefined' && actualField.id) {
                    const editor = tinymce.get(actualField.id);
                    if (editor) {
                        editor.setContent(data.result);
                        editor.fire('change');
                    } else {
                        actualField.value = data.result;
                    }
                } else if (actualField.classList.contains('InputfieldCKEditorNormal') && typeof CKEDITOR !== 'undefined' && actualField.id) {
                    const editor = CKEDITOR.instances[actualField.id];
                    editor.setData(data.result);
                } else {
                    actualField.value = data.result;
                }

                actualField.dispatchEvent(new Event('change', { bubbles: true }));
                actualField.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                showError(data.error || 'AI processing failed', actualField);
            }
        })
        .catch(error => {
            hideLoading(actualField, inputfield);
            showError('Network error: ' + error.message, actualField);
        });
    }

    /**
     * Extract the subfield name from a file/image input's name attribute.
     * Pattern: {subfield}{langid?}_{fieldname}{_repeaterXXXX?}_{hash}
     * Returns the subfield name (e.g. "description", "alt_text") or null.
     */
    function extractSubfieldName(inputName) {
        var hashMatch = inputName.match(/^(.+)_([a-f0-9]{32})$/);
        if (!hashMatch) return null;

        var prefix = hashMatch[1];

        // Remove repeater suffix if present
        var repeaterMatch = prefix.match(/_repeater\d+$/);
        if (repeaterMatch) {
            prefix = prefix.substring(0, prefix.length - repeaterMatch[0].length);
        }

        // Field name is the last segment, subfield is everything before it
        var lastUnderscore = prefix.lastIndexOf('_');
        if (lastUnderscore !== -1) {
            var subfieldWithLang = prefix.substring(0, lastUnderscore);
            // Strip trailing lang suffix (digits only)
            return subfieldWithLang.replace(/\d+$/, '');
        }

        return null;
    }

    /**
     * Create button container with dropdown for an input element
     */
    function createPromptsForInput(input, indices) {
        // Skip if already has button container
        if (input.nextElementSibling && input.nextElementSibling.classList.contains('promptai-container')) return;

        // Create container for button and dropdown
        const container = document.createElement('div');
        container.className = 'promptai-container';

        const icon = document.createElement('i');
        icon.className = 'fa fa-magic promptai-icon';
        container.appendChild(icon);

        indices.forEach(function(promptIndex) {
            const prompt = config.prompts[promptIndex];
            if (!prompt) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'promptai-button uk-button uk-button-small uk-button-text uk-margin-small-right';
            button.textContent = prompt.label;
            button.dataset.promptIndex = promptIndex;
            container.appendChild(button);
        });

        const loading = document.createElement('div');
        loading.className = 'promptai-loading';
        const icon2 = document.createElement('i');
        icon2.className = 'fa fa-magic promptai-loading-icon';
        loading.appendChild(icon2);
        container.appendChild(loading);

        // Insert container after the input
        input.parentNode.insertBefore(container, input.nextSibling);

        // Dropdown item click handler
        container.addEventListener('click', function(e) {
            const button = e.target.closest('button');
            if (!button || !button.dataset.promptIndex) return;

            const promptIndex = button.dataset.promptIndex;
            const prompt = config.prompts[promptIndex];

            if (!prompt) return;

            // Find the parent Inputfield
            const inputfield = input.closest('.Inputfield');
            processField(input, prompt, inputfield);
        });
    }

    /**
     * Add buttons to text field inputs
     */
    function addButtonsToTextFields() {
        // Find all text fields marked for PromptAI
        const textFields = document.querySelectorAll('.Inputfield[data-promptai-textfield="1"]');

        textFields.forEach(function(textField) {
            const promptIndices = textField.getAttribute('data-promptai-prompts');
            if (!promptIndices) return;

            const indices = JSON.parse(promptIndices);

            // Find the input/textarea within this field
            // For TinyMCE fields, skip the hidden textarea and use the visible editor container
            let input = null;

            // Check if this is a TinyMCE field
            const tinymceTextarea = textField.querySelector('textarea.InputfieldTinyMCEEditor');
            if (tinymceTextarea) {
                // TinyMCE field - find the visible editor wrapper
                // TinyMCE creates a wrapper after the textarea, we'll add the button after that wrapper
                const editorWrapper = textField.querySelector('.mce-tinymce, .tox-tinymce');
                if (editorWrapper) {
                    input = editorWrapper;
                } else {
                    // Editor not yet initialized, skip for now (will be caught by MutationObserver)
                    return;
                }
            } else {
                // Regular input or textarea
                input = textField.querySelector('input[type="text"], textarea:not(.InputfieldTinyMCEEditor)');
            }

            if (!input) return;

            // Check if button already exists
            const existingContainer = input.parentNode.querySelector('.promptai-container');
            if (existingContainer) return;

            createPromptsForInput(input, indices);
        });
    }

    /**
     * Add buttons to file/image field description inputs
     */
    function addButtonsToFileFields() {
        // Find all file fields marked for PromptAI
        const fileFields = document.querySelectorAll('.Inputfield[data-promptai-filefield="1"]');

        fileFields.forEach(function(fileField) {
            const promptIndices = fileField.getAttribute('data-promptai-prompts');
            if (!promptIndices) return;

            const indices = JSON.parse(promptIndices);

            // Find all text inputs/textareas within file/image data sections
            // These are subfield inputs (description, alt_text, caption, etc.)
            // Exclude system fields (sort, delete, hidden, checkbox)
            const subFieldInputs = fileField.querySelectorAll(
                '.InputfieldFileData input[type="text"], .InputfieldFileData textarea, ' +
                '.InputfieldImageEdit input[type="text"], .InputfieldImageEdit textarea, ' +
                '.InputfieldImageEdit__core input[type="text"], .InputfieldImageEdit__core textarea'
            );

            subFieldInputs.forEach(function(input) {
                // Skip system/hidden inputs and checkboxes
                if (input.type === 'hidden' || input.type === 'checkbox' ||
                    input.name.startsWith('sort_') || input.name.startsWith('delete_') ||
                    input.name.startsWith('rename_')) return;

                // Skip textareas that TinyMCE has hidden (the visible editor gets the button instead)
                if (input.tagName === 'TEXTAREA' && input.classList.contains('InputfieldTinyMCEEditor') && input.style.display === 'none') return;

                // Only show prompts that target this specific subfield
                var subfield = extractSubfieldName(input.name);
                var matchingIndices = indices.filter(function(promptIndex) {
                    var prompt = config.prompts[promptIndex];
                    if (!prompt) return false;
                    var targetSubfield = prompt.targetSubfield || 'description';
                    return subfield === targetSubfield;
                });

                if (matchingIndices.length === 0) return;

                // For TinyMCE fields, attach button to the visible editor wrapper instead
                var target = input;
                if (input.tagName === 'TEXTAREA' && input.classList.contains('InputfieldTinyMCEEditor')) {
                    var editorWrapper = input.parentNode.querySelector('.mce-tinymce, .tox-tinymce');
                    if (editorWrapper) {
                        target = editorWrapper;
                    } else {
                        // Editor not yet initialized, skip (MutationObserver will retry)
                        return;
                    }
                }

                createPromptsForInput(target, matchingIndices);
            });
        });
    }

    /**
     * Close dropdowns when clicking outside
     */
    function setupGlobalClickHandler() {
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.promptai-magic-btn') && !e.target.closest('.promptai-dropdown')) {
                document.querySelectorAll('.promptai-dropdown').forEach(d => {
                    d.classList.add('promptai-dropdown-hidden');
                    d.classList.remove('is-visible');
                });
            }
        });
    }

    /**
     * Initialize event listeners
     */
    function init() {
        if (!config.prompts || Object.keys(config.prompts).length === 0) {
            return;
        }

        if (!config.ajaxUrl) {
            return;
        }

        // Add buttons to text fields
        addButtonsToTextFields();

        // Add buttons to file field description inputs
        addButtonsToFileFields();

        // Setup global click handler to close dropdowns
        setupGlobalClickHandler();

        // Re-add buttons when fields are added dynamically (e.g., new file uploaded, repeater added)
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    addButtonsToTextFields();
                    addButtonsToFileFields();
                }
            });
        });

        const contentBody = document.querySelector('#pw-content-body');
        if (contentBody) {
            observer.observe(contentBody, { childList: true, subtree: true });
        }
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
