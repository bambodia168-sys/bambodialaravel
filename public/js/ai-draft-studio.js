/* Bambodia Builder AI Draft Studio. No auto-save, no rendering model HTML. */
document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const dialog = document.querySelector('[data-ai-draft-dialog]');
    const studio = document.querySelector('[data-builder-studio]');
    if (!(dialog instanceof HTMLDialogElement) || !studio || studio.dataset.builderReadonly === '1') return;

    const form = dialog.querySelector('[data-ai-generate-form]');
    const review = dialog.querySelector('[data-ai-review]');
    const status = dialog.querySelector('[data-ai-status]');
    const generateButton = dialog.querySelector('[data-ai-generate]');
    const applyButton = dialog.querySelector('[data-ai-apply]');
    const title = dialog.querySelector('[data-ai-title]');
    const description = dialog.querySelector('[data-ai-description]');
    const builderTitle = studio.querySelector('input[name="title"]');
    const editor = studio.querySelector('[data-studio-editor]');
    const surface = editor?.querySelector('[data-editor-surface]');
    const fallback = editor?.querySelector('[data-editor-fallback]');
    const html = editor?.querySelector('[data-editor-html]');
    const source = editor?.querySelector('[data-editor-source]');
    let activeDraft = null;
    let requesting = false;

    const notify = (message, error = false) => {
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('studio-editor-counter-error', error);
    };

    const token = () => form?.querySelector('input[name="_token"]')?.value || '';
    const send = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token(),
            },
            body: JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = Object.values(payload.errors || {})[0];
            const message = Array.isArray(validation) ? validation[0] : payload.message;
            throw Error(message || 'The request could not be completed.');
        }
        return payload;
    };

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (requesting || !form.reportValidity()) return;
        requesting = true;
        activeDraft = null;
        review.hidden = true;
        generateButton.disabled = true;
        notify('Generating a private suggestion…');
        try {
            const fields = new FormData(form);
            const result = await send(dialog.dataset.generateUrl, {
                type: dialog.dataset.contentType,
                reference: dialog.dataset.recordReference || null,
                brief: fields.get('brief'),
                tone: fields.get('tone'),
                locale: fields.get('locale'),
                consent: fields.get('consent') || false,
            });
            if (!Number.isSafeInteger(result.id) ||
                typeof result.title !== 'string' || typeof result.description !== 'string') {
                throw Error('Provider response was invalid.');
            }
            activeDraft = result.id;
            title.value = result.title;
            description.value = result.description;
            review.hidden = false;
            notify('Review all claims and edit freely. '
                + Math.max(0, Number(result.remaining) || 0) + ' requests remain today.');
            title.focus();
        } catch (error) {
            notify(error.message || 'Generation failed. Try again later.', true);
        } finally {
            requesting = false;
            generateButton.disabled = false;
        }
    });

    dialog.querySelector('[data-ai-discard]')?.addEventListener('click', () => {
        activeDraft = null;
        review.hidden = true;
        notify('Suggestion discarded. Existing Builder content was not changed.');
    });

    applyButton?.addEventListener('click', async () => {
        if (requesting || !activeDraft || !builderTitle || !fallback) return;
        const newTitle = title.value.trim();
        const newDescription = description.value.trim();
        const minimum = dialog.dataset.contentType === 'job' ? 70 : 40;
        if (newTitle.length < 8 || newTitle.length > 180 ||
            newDescription.length < minimum || newDescription.length > 10000) {
            notify('Check title and description lengths before applying.', true);
            return;
        }
        if (source && !source.hidden) {
            notify('Close HTML source mode before applying your reviewed suggestion.', true);
            return;
        }

        requesting = true;
        applyButton.disabled = true;
        notify('Confirming your review…');
        try {
            const reviewUrl = dialog.dataset.reviewUrlTemplate.replace('__ID__', String(activeDraft));
            await send(reviewUrl, {confirmed: true});
            builderTitle.value = newTitle;
            builderTitle.dispatchEvent(new Event('input', {bubbles: true}));
            if (surface && !surface.hidden) {
                // textContent prevents arbitrary scripts and HTML from AI output.
                surface.textContent = newDescription;
                surface.dispatchEvent(new Event('input', {bubbles: true}));
            } else {
                fallback.value = newDescription;
                fallback.dispatchEvent(new Event('input', {bubbles: true}));
                if (html) html.value = '';
            }
            activeDraft = null;
            review.hidden = true;
            dialog.close();
            editor?.scrollIntoView({block: 'nearest', behavior: 'smooth'});
        } catch (error) {
            notify(error.message || 'Review could not be confirmed.', true);
        } finally {
            requesting = false;
            applyButton.disabled = false;
        }
    });

    dialog.addEventListener('close', () => {
        if (!requesting) {
            activeDraft = null;
            review.hidden = true;
        }
    });
});
