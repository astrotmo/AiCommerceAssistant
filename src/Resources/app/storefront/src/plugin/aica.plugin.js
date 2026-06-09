/**
 * AicaPlugin for Shopware
 *
 * Implements a floating AICA interface within the Shopware storefront.
 *
 * @package    AiCommerceAssistant
 * @author     Paul Nöth
 */

import { marked } from 'marked';
import DOMPurify from 'dompurify';

const Base = window.PluginBaseClass;

/**
 * Class AicaPlugin
 *
 * Features:
 * - Shows a floating chat button (FAB).
 * - Opens a small chat window at the bottom left or right.
 * - Sends user inputs to a same-origin Shopware proxy route (this.chatUrl).
 * - Expects structured JSON responses with { type, blocks }.
 * - Renders text bubbles, product lists, and info boxes.
 * - Stores history and open state in sessionStorage.
 */
export default class AicaPlugin extends Base {

    /** LIFE CYCLE */

    /**
     * Initialising the plugin: reading config, building UI, restoring history.
     */
    init() {
        const {
            chatUrl,
            llmModel,
            welcomeMessage,
            buttonLabel,
            autoOpen,
            position,
            contextTokenUrl,
            trackUrl,
            debugEnabled,
            productPriceVisibility,
            productPriceMode,
            showProductPriceHint,
            canShowProductPrices,
        } = this.el.dataset;

        this.debug = debugEnabled === '1';

        this.debugLog('Plugin init');
        this.debugLog('Root element:', this.el);
        this.debugLog('Dataset:', this.el?.dataset);
        this.debugLog('Client context:', this.getClientContext());

        this.chatUrl = this.normalizeUrl(chatUrl || '/aica/chat');
        if (!this.chatUrl) {
            this.debugWarn('Kein chatUrl gesetzt, Plugin wird nicht initialisiert.');
            return;
        }
        this.debugLog('Using storefront chat route:', this.chatUrl);
        this.llmModel = llmModel || '';
        this.welcomeMessage = welcomeMessage || 'Hallo, wie kann ich behilflich sein?';

        this.buttonLabel = buttonLabel || 'Chat';
        this.autoOpen = autoOpen === '1';
        this.position = (position || 'bottom-left').toLowerCase(); // 'bottom-left' | 'bottom-right'
        this.preferredPosition = this.position;
        this.productPriceVisibility = this.normalizeProductPriceVisibility(productPriceVisibility || 'customers');
        this.productPriceMode = this.normalizeProductPriceMode(productPriceMode || 'advanced-first');
        this.showProductPriceHint = showProductPriceHint !== '0';
        this.canShowProductPrices = canShowProductPrices === '1' && this.productPriceVisibility !== 'none';

        this.wrapper = null;
        this.fab = null;
        this.panel = null;
        this.messagesEl = null;
        this.form = null;
        this.input = null;
        this.sendBtn = null;

        this.contextToken = null;
        this.contextTokenExpMs = 0;
        this.contextTokenUrl = contextTokenUrl || '';
        this.trackEndpoint = trackUrl || '/aica/track';
        this.contactEndpoint = '/aica/contact';
        this.maxMessageLength = 2000;
        this.maxStoredMessages = 60;
        this.maxHistoryEntries = 12;
        this.maxHistoryEntryChars = 1200;
        this.maxHistoryTotalChars = 8000;
        this.maxQueuedRequests = 5;
        this.pendingChatRequests = 0;
        this.requestSequence = 0;
        this.clientMessageIndex = 0;
        this.isRestoringHistory = false;
        this.chatQueue = Promise.resolve();

        this.buildUi();
        this.restoreHistory();

        if (this.autoOpen) {
            // Small delay that everything is rendered before opening
            setTimeout(() => this.open(), 400);
        }

        // Always scroll to the end on resize
        window.addEventListener('resize', () => this.scrollMessagesToEnd());
    }

    /** DEBUGGING HELPERS */

    debugLog(...args) {
    if (!this.debug) return;
    console.debug('[AICA]', ...args);
    }

    debugWarn(...args) {
        if (!this.debug) return;
        console.warn('[AICA]', ...args);
    }

    debugError(...args) {
        if (!this.debug) return;
        console.error('[AICA]', ...args);
    }

    /** UI SETUP & WINDOW STATE */

    /**
     * Helper function: Ensures the URL is absolute or reasonably relative.
     * 
     * @param {string} url The input URL from data attribute.
     * @returns {string} Normalized URL.
     */
    normalizeUrl(url) {
        if (!url) return '';

        let candidate = url;
        if (!url.startsWith('http://') && !url.startsWith('https://') && !url.startsWith('/')) {
            candidate = `${window.location.origin.replace(/\/+$/, '')}/${url.replace(/^\/+/, '')}`;
        }

        try {
            const parsed = new URL(candidate, window.location.origin);
            if (!this.isAllowedChatUrl(parsed)) {
                this.debugWarn('Unsicherer chatUrl blockiert:', parsed.toString());
                return '';
            }

            return parsed.toString();
        } catch {
            return '';
        }
    }

    isAllowedChatUrl(parsedUrl) {
        if (!(parsedUrl instanceof URL)) return false;

        if (parsedUrl.protocol === 'https:') {
            return true;
        }

        if (parsedUrl.protocol !== 'http:') {
            return false;
        }

        const localhost = ['localhost', '127.0.0.1', '[::1]', '::1'];
        if (localhost.includes(parsedUrl.hostname)) {
            return true;
        }

        return parsedUrl.origin === window.location.origin;
    }

    normalizeProductPriceVisibility(value) {
        const normalized = String(value || '').trim().toLowerCase();
        return ['all', 'customers', 'none'].includes(normalized) ? normalized : 'customers';
    }

    normalizeProductPriceMode(value) {
        const normalized = String(value || '').trim().toLowerCase();
        return ['calculated', 'advanced-first'].includes(normalized) ? normalized : 'advanced-first';
    }

    /**
     * Builds the chat UI elements and appends them to the document body.
     */
    buildUi() {
        // Root container (floating)
        const wrapper = document.createElement('div');
        wrapper.className = 'aica-wrap';
        wrapper.classList.add(
            this.position === 'bottom-right'
                ? 'aica-bottom-right'
                : 'aica-bottom-left'
        );
        this.wrapper = wrapper;

        // Floating-Action-Button
        const fab = document.createElement('button');
        fab.type = 'button';
        fab.className = 'aica-button';
        fab.setAttribute('aria-haspopup', 'dialog');
        fab.setAttribute('aria-expanded', 'false');
        fab.textContent = this.buttonLabel;
        fab.addEventListener('click', () => this.toggle());
        this.fab = fab;

        // Chat window (Panel, initially hidden)
        const panel = document.createElement('section');
        panel.className = 'aica-window';
        panel.setAttribute('aria-label', 'Chat mit Assistenz');
        panel.setAttribute('role', 'dialog');
        panel.style.display = 'none';
        this.panel = panel;

        // Header (Titel, Clear-Icon, Close-Icon)
        const header = document.createElement('header');
        header.className = 'aica-header';

        const titleEl = document.createElement('div');
        titleEl.className = 'aica-title';
        titleEl.textContent = this.buttonLabel;

        const controls = document.createElement('div');
        controls.className = 'aica-header-controls';

        // „Verlauf löschen“ (Trash-Icon)
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'aica-clear';
        clearBtn.title = 'Chatverlauf löschen';
        clearBtn.innerHTML = '🗑️';
        clearBtn.addEventListener('click', () => this.clearHistory());

        // Close (X)
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'aica-close';
        closeBtn.title = 'Chat schließen';
        closeBtn.innerHTML = '×';
        closeBtn.addEventListener('click', () => this.close());

        controls.appendChild(clearBtn);
        controls.appendChild(closeBtn);

        header.appendChild(titleEl);
        header.appendChild(controls);

        // Messages container
        const messagesEl = document.createElement('div');
        messagesEl.className = 'aica-messages';
        this.messagesEl = messagesEl;

        // Input form
        const form = document.createElement('form');
        form.className = 'aica-form';
        this.form = form;

        const input = document.createElement('textarea');
        input.className = 'aica-input';
        input.rows = 1;                     // Start height
        input.placeholder = 'Enter message …';
        input.style.resize = 'none';        // no manual resizing
        input.style.overflow = 'hidden';    // hide scrollbar (grows automatically)

        this.input = input;

        // Auto-Resize on input:
        input.addEventListener('input', () => {
            input.style.height = 'auto';                 
            input.style.height = input.scrollHeight + 'px';
            input.style.transition = 'height 0.1s ease';
        });

        const sendBtn = document.createElement('button');
        sendBtn.type = 'submit';
        sendBtn.className = 'aica-send';
        sendBtn.textContent = 'Senden';
        this.sendBtn = sendBtn;

        form.appendChild(input);
        form.appendChild(sendBtn);

        form.addEventListener('submit', (e) => this.onSubmit(e));

        // Assemble chat window
        panel.appendChild(header);
        panel.appendChild(messagesEl);
        panel.appendChild(form);

        // Anchor everything in the wrapper
        wrapper.appendChild(fab);
        wrapper.appendChild(panel);
        document.body.appendChild(wrapper);

        // Show hint for contact form for not logged in users
        this.showContactFormHint();
        
        // Append welcome message from bot
        this.appendBubble(this.welcomeMessage, 'bot');
    }

    /**
     * Open the chat window.
     * Sets focus to the input field.
     */
    open() {
        if (!this.panel) return;
        this.panel.style.display = 'flex';
        this.wrapper.classList.add('aica-open');
        this.fab.setAttribute('aria-expanded', 'true');
        this.saveOpenState(true);
        this.scrollMessagesToEnd();
        this.input && this.input.focus();
        this.debugLog('Chat opened');
    }

    /**
     * Close the chat window.
     * Removes focus from the input field.
     */
    close() {
        if (!this.panel) return;
        this.panel.style.display = 'none';
        this.wrapper.classList.remove('aica-open');
        this.fab.setAttribute('aria-expanded', 'false');
        this.saveOpenState(false);
        this.debugLog('Chat closed');
    }

    /**
     * Toggle the chat window open/closed.
     */
    toggle() {
        if (!this.panel) return;
        const isOpen = this.panel.style.display !== 'none';
        if (isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    /**
     * Scrolls the messages container to the end.
     */
    scrollMessagesToEnd() {
        if (!this.messagesEl) return;
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    }

    /** RENDERING PRIMITIVES */

    /**
     * Renders markdown text to sanitized HTML.
     * 
     * @param {string} markdown The markdown text.
     * @returns {string} The sanitized HTML.
     */
    renderMarkdown(markdown) {
        if (!markdown || typeof markdown !== 'string') return '';

        const cleaned = markdown.trim();

        const html = marked.parse(cleaned, {
            gfm: true,
            breaks: true,
        });

        return DOMPurify.sanitize(html);
    }

    /**
     * Detects links in the text and makes them clickable.
     */
    linkify(text) {
        if (!text) return '';
        const escaped = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        const urlRegex = /(https?:\/\/[^\s]+)/g;
        return escaped.replace(urlRegex, (url) => {
            const safeUrl = url.replace(/"/g, '&quot;');
            return `<a href="${safeUrl}" target="_self" class="aica-link">${safeUrl}</a>`;
        });
    }

    /**
     * Adds a chat bubble.
     * 
     * @param {string} text The text content of the bubble.
     * @param {string} who 'user' or 'bot' to distinguish styles.
     * @param {boolean} isError Whether it is an error message.
     * @returns {HTMLElement|null} The created bubble element or null.
     */
    appendBubble(text, who = 'bot', isError = false) {
        if (!this.messagesEl) return null;

        const bubble = document.createElement('div');
        bubble.className = 'aica-bubble ' + (who === 'user' ? 'from-user' : 'from-bot');
        if (isError) bubble.classList.add('is-error');

        const inner = document.createElement('div');
        inner.className = 'aica-bubble-inner';
        inner.innerHTML = this.renderMarkdown(text || '');

        bubble.appendChild(inner);
        this.messagesEl.appendChild(bubble);
        this.scrollMessagesToEnd();
        return bubble;
    }

    /**
     * Small "Typing..." placeholder during the response.
     */
    appendPending() {
        const el = document.createElement('div');
        el.className = 'aica-pending';
        el.textContent = '…';
        this.messagesEl.appendChild(el);
        this.scrollMessagesToEnd();
        return el;
    }

    /** CHAT REQUEST FLOW */

    /**
     * Handles form submission: sends user message to backend and processes response.
     * 
     * @param {Event} e The submit event.
     */
    async onSubmit(e) {
        e.preventDefault();
        if (!this.chatUrl || !this.input) return;

        const message = (this.input.value || '').trim();
        if (!message) return;

        if (message.length > this.maxMessageLength) {
            this.appendBubble(`Bitte maximal ${this.maxMessageLength} Zeichen senden.`, 'bot', true);
            return;
        }

        const client = this.getClientContext();

        const history = this.getHistoryForRequest();

        // Show user bubble immediately
        this.appendBubble(message, 'user');
        this.persistMessage({ who: 'user', text: message });
        const userMessageIndex = this.nextClientMessageIndex();

        this.trackEvent({
            event: 'message',
            role: 'user',
            messageIndex: userMessageIndex,
            requestId: this.requestSequence + 1,
            messageType: 'text',
            model: this.llmModel || null,
            text: message,
        });

        this.input.value = '';
        this.input.focus();

        if (this.pendingChatRequests >= this.maxQueuedRequests) {
            this.appendBubble('Zu viele gleichzeitige Anfragen. Bitte kurz warten.', 'bot', true);
            return;
        }

        const requestId = ++this.requestSequence;
        this.pendingChatRequests += 1;

        this.chatQueue = this.chatQueue
            .catch(() => {})
            .then(() => this.processChatRequest({ requestId, message, history, client }))
            .finally(() => {
                this.pendingChatRequests = Math.max(0, this.pendingChatRequests - 1);
            });
    }

    async processChatRequest({ requestId, message, history, client }) {
        const pending = this.appendPending();
        const startedAt = performance.now();

        try {
            const contextToken = await this.getContextToken(this.contextTokenUrl);
            const load = {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-AICA': '1',
                },
                body: JSON.stringify({
                    requestId,
                    message,
                    model: this.llmModel,
                    client: {
                        origin: client.origin,
                        basePath: client.basePath,
                        fullBaseUrl: client.fullBaseUrl,
                        contextToken: contextToken || '',
                    },
                    history: history || [],
                }),
            };

            this.debugLog('Sending chat payload to storefront proxy', { url: this.chatUrl, request: load });

            const res = await fetch(this.chatUrl, load);

            const text = await res.text();
            if (!res.ok) {
                throw new Error(text || `HTTP ${res.status}`);
            }

            let payload;
            try {
                payload = JSON.parse(text);
            } catch {
                payload = null;
            }

            pending.remove();

            this.debugLog('Bot response payload:', payload, 'raw text:', text);

            const latencyMs = this.resolveLatencyMs(payload, Math.max(0, Math.round(performance.now() - startedAt)));
            const botResult = this.handleBotResponse(payload, text);
            const tokenUsage = this.extractTokenUsage(payload);
            const productReferences = this.extractProductReferences(payload);
            const flags = this.extractResponseFlags(payload);

            this.trackEvent({
                event: 'message',
                role: 'assistant',
                requestId,
                messageIndex: this.nextClientMessageIndex(),
                messageType: botResult.messageType,
                model: this.extractModelName(payload) || this.llmModel || null,
                text: botResult.text,
                payload: payload && typeof payload === 'object' ? payload : null,
                tokenUsage,
                latencyMs,
                flags,
                productReferences,
                isError: false,
            });
        } catch (err) {
            pending.remove();
            const msg = (err && err.message) ? err.message : String(err);
            const safeMessage = this.truncateText(msg, 500);
            this.appendBubble(`Error: ${safeMessage}`, 'bot', true);
            this.persistMessage({ who: 'bot', text: `Error: ${safeMessage}`, isError: true });

            this.trackEvent({
                event: 'message',
                role: 'assistant',
                requestId,
                messageIndex: this.nextClientMessageIndex(),
                messageType: 'error',
                model: this.llmModel || null,
                text: `Error: ${safeMessage}`,
                flags: {
                    containsProductSuggestions: false,
                    containsInfoBox: false,
                    containsFormular: false,
                    containsToolPayload: false,
                    isError: true,
                },
                isError: true,
            });
        }
    }

    /**
     * Central entry point to handle the bot response.
     * 
     * @param {*} payload the parsed JSON response
     * @param {*} rawText the raw text response (fallback)
     */
    handleBotResponse(payload, rawText) {
        if (payload && Array.isArray(payload.blocks)) {
            this.renderBlocks(payload.blocks);
            this.persistMessage({ who: 'bot', structured: true, payload });
            return {
                text: this.stringifyBlocks(payload.blocks),
                messageType: 'structured',
            };
        }

        // Fallback: simple text (reply/message or rawText)
        const replyText = (payload && (payload.reply ?? payload.message)) || rawText;
        this.appendBubble(replyText.toString(), 'bot');
        this.persistMessage({ who: 'bot', text: replyText.toString() });

        return {
            text: replyText.toString(),
            messageType: 'text',
        };
    }

    /** STRUCTURED BLOCK RENDERING */

    /**
     * Renders an array of blocks from the bot response.
     * 
     * @param {Array} blocks The array of blocks to render.
     */
    renderBlocks(blocks) {
        if (!Array.isArray(blocks)) return;
        for (const block of blocks) {
            this.renderBlock(block);
        }
        this.scrollMessagesToEnd();
    }

    /**
     * Renders a single block based on its kind.
     * 
     * @param {Object} block The block object to render.
     */
    renderBlock(block) {
        if (!block || typeof block !== 'object') return;

        switch (block.kind) {
            case 'text':
                if (block.text) {
                    this.appendBubble(block.text, 'bot');
                }
                break;

            case 'product_list':
                this.renderProductList(block);
                break;

            case 'info_box':
                this.renderInfoBox(block);
                break;

            case 'formular':
                this.renderFormular(block);
                break;

            default:
                // Unknown block type -> if text is present, render as a simple bubble
                if (block.text) {
                    this.appendBubble(block.text, 'bot');
                }
        }
    }

    /**
     * Renders a product list as tiles (kind: "product_list").
     * Expected:
     * {
     *   title: "string",
     *   products: [{ id, name, detailUrl, price?, currency? }, ...]
     * }
     * 
     * @param {Object} block The product list block.
     */
    renderProductList(block) {
        if (!this.messagesEl) return;

        this.debugLog('Rendering product list block', block);

        const clientContext = this.getClientContext();
        const origin = clientContext.origin;

        const container = document.createElement('div');
        container.className = 'aica-product-list';

        if (block.title) {
            const titleEl = document.createElement('div');
            titleEl.className = 'aica-product-list-title';
            titleEl.textContent = block.title;
            container.appendChild(titleEl);
        }

        const itemsWrap = document.createElement('div');
        itemsWrap.className = 'aica-product-list-items';

        const products = Array.isArray(block.products) ? block.products : [];

        const stdPriceHint = 'Dies ist der Standardpreis.';
        const advPriceHint = 'Dies ist ihr persönlich reduzierter Preis.';

        const setPriceDisplay = (el, formattedPrice, isAdvancedPrice) => {
            const priceText = String(formattedPrice || '').trim();
            if (!priceText) return;

            el.textContent = this.showProductPriceHint ? `${priceText} *` : priceText;
            el.style.display = '';
            el.classList.toggle('is-advanced-price', isAdvancedPrice);
            el.classList.toggle('is-standard-price', !isAdvancedPrice);

            if (this.showProductPriceHint) {
                el.dataset.priceHint = isAdvancedPrice ? advPriceHint : stdPriceHint;
                el.setAttribute('aria-label', el.dataset.priceHint);
            } else {
                delete el.dataset.priceHint;
                el.removeAttribute('aria-label');
            }
        };

        // Keep references to price elements so we can update later
        const priceElsById = new Map();
        
        for (const p of products) {
            const productId = typeof p?.id === 'string' ? p.id.trim() : '';
            if (!p || !p.name || productId === '') continue;

            const card = document.createElement('a');
            card.className = 'aica-product-card';
            card.href = `${origin}/detail/${encodeURIComponent(productId)}`;
            card.target = '_self';

            const nameEl = document.createElement('div');
            nameEl.className = 'aica-product-name';
            nameEl.textContent = p.name;
            card.appendChild(nameEl);

            const metaEl = document.createElement('div');
            metaEl.className = 'aica-product-meta';

            if (p.productNumber) {
                const numberEl = document.createElement('span');
                numberEl.className = 'aica-product-number';
                numberEl.textContent = "Art. " + p.productNumber;
                metaEl.appendChild(numberEl);
            }

            // Always create a price element, but keep it empty/hidden if no price
            const priceEl = document.createElement('span');
            priceEl.className = 'aica-product-price';

            if (this.canShowProductPrices && p.price) {
                setPriceDisplay(priceEl, p.price, false);
            } else {
                // Placeholder (only shown if we later get a price)
                priceEl.textContent = '';
                priceEl.style.display = 'none';
            }

            metaEl.appendChild(priceEl);

            if (p.purchaseUnit) {
                const unitEl = document.createElement('span');
                const unitCode = p.unitShortCode || '';
                const unitVal = p.purchaseUnit;
                unitEl.className = 'aica-product-unit';
                unitEl.textContent = ` / ${unitVal} ${unitCode}`;
                metaEl.appendChild(unitEl);
            }

            card.appendChild(metaEl);

            if (this.canShowProductPrices) {
                priceElsById.set(productId, priceEl);
            }
            itemsWrap.appendChild(card);
        }

        container.appendChild(itemsWrap);
        this.messagesEl.appendChild(container);
        this.scrollMessagesToEnd();

        if (!this.canShowProductPrices) return;

        // Enrich with calculated prices according to the configured visibility.
        const ids = [...priceElsById.keys()];
        if (ids.length === 0) return;

        this.debugLog('Requesting prices for product IDs', ids);

        // Fire and forget (no UI blocking)
        this.fetchCalculatedPrices(ids).then((pricesById) => {
            this.debugLog('Applying calculated prices', priceElsById);

            for (const [id, el] of priceElsById.entries()) {
                const prod = pricesById[id];

                if (!prod) {
                    this.debugWarn('No calculated price for product', id, prod);
                    continue;
                }

                const { price, isAdvancedPrice } = this.resolveProductPrice(prod);
                if (price === null) {
                    this.debugWarn('No usable price fields for product', id, prod);
                    continue;
                }

                setPriceDisplay(el, this.formatProductPrice(price, prod?.currency), isAdvancedPrice);
            }
            this.scrollMessagesToEnd();
        });
    }

    resolveProductPrice(prod) {
        const base = prod?.calculatedPrice?.unitPrice;

        if (this.productPriceMode === 'calculated') {
            return {
                price: typeof base === 'number' ? base : null,
                isAdvancedPrice: false,
            };
        }

        const tiers = Array.isArray(prod?.calculatedPrices) ? prod.calculatedPrices : [];
        const preferredTier = tiers.find(t => t && t.quantity === 1 && typeof t.unitPrice === 'number')
            || tiers.find(t => t && typeof t.unitPrice === 'number');

        if (preferredTier) {
            return {
                price: preferredTier.unitPrice,
                isAdvancedPrice: true,
            };
        }

        return {
            price: typeof base === 'number' ? base : null,
            isAdvancedPrice: false,
        };
    }

    formatProductPrice(price, currency) {
        const currencyCode = typeof currency === 'string' && currency.trim() ? currency.trim() : 'EUR';
        const locale = document.documentElement.lang || 'de-DE';

        try {
            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: currencyCode,
            }).format(price);
        } catch {
            return new Intl.NumberFormat('de-DE', {
                style: 'currency',
                currency: 'EUR',
            }).format(price);
        }
    }

    /**
     * Renders an info box (kind: "info_box").
     * Expected:
     * {
     *   style: "info" | "warning" | "error",
     *   title: "string",
     *   text: "string"
     * }
     * 
     * @param {Object} block The info box block.
     */
    renderInfoBox(block) {
        if (!this.messagesEl) return;

        const box = document.createElement('div');
        box.className = 'aica-info-box';
        if (block.style) {
            box.classList.add(`aica-info-${block.style}`);
        }

        if (block.title) {
            const titleEl = document.createElement('div');
            titleEl.className = 'aica-info-title';
            titleEl.textContent = block.title;
            box.appendChild(titleEl);
        }

        if (block.text) {
            const textEl = document.createElement('div');
            textEl.className = 'aica-info-text';
            textEl.innerHTML = this.renderMarkdown(block.text);
            box.appendChild(textEl);
        }

        this.messagesEl.appendChild(box);
        this.scrollMessagesToEnd();
    }

    /**
     * Renders a contact form (kind: "formular").
     * Expected:
     * {
     *   title: "string",
     *   text: "string",
     *   fields: [{ name, label, type, placeholder, required, options? }, ...],
     *   submitLabel: "string",
     *   contactUrl: "string" (optional URL to open on submit)
     * }
     * 
     * @param {Object} block The formular block.
     */
    renderFormular(block) {
        if (!this.messagesEl) return;

        const container = document.createElement('div');
        container.className = 'aica-formular';

        const header = document.createElement('div');
        header.className = 'aica-formular-header';

        const titleEl = document.createElement('div');
        titleEl.className = 'aica-formular-title';
        titleEl.textContent = block.title || 'Kontaktformular';

        const toggle = document.createElement('div');
        toggle.className = 'aica-formular-toggle';
        toggle.textContent = '▼';

        header.appendChild(titleEl);
        header.appendChild(toggle);
        container.appendChild(header);

        const body = document.createElement('div');
        body.className = 'aica-formular-body';

        const form = document.createElement('form');
        form.className = 'aica-formular-form';

        if (block.reason) {
            const reasonEl = document.createElement('div');
            reasonEl.className = 'aica-formular-reason';
            reasonEl.innerHTML = this.renderMarkdown(block.reason);
            body.appendChild(reasonEl);

            const sourceContext = document.createElement('input');
            sourceContext.type = 'hidden';
            sourceContext.name = 'sourceContext';
            sourceContext.value = String(block.reason);
            form.appendChild(sourceContext);
        }

        for (const f of block.fields || []) {
            if (!f?.key) continue;

            const row = document.createElement('label');
            row.className = 'aica-formular-row';

            const labelEl = document.createElement('div');
            labelEl.className = 'aica-formular-label';
            labelEl.textContent = f.label || f.key;

            let input;
            if (f.type === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 4;
            } else {
                input = document.createElement('input');
                input.type = f.type || 'text';
            }

            input.className = 'aica-formular-input';
            input.name = f.key;
            if (f.placeholder) input.placeholder = f.placeholder;
            if (f.required) input.required = true;
            if (f.value != null) input.value = String(f.value);

            row.appendChild(labelEl);
            row.appendChild(input);
            form.appendChild(row);
        }

        const honeypot = document.createElement('input');
        honeypot.type = 'text';
        honeypot.name = 'website';
        honeypot.tabIndex = -1;
        honeypot.autocomplete = 'off';
        honeypot.setAttribute('aria-hidden', 'true');
        honeypot.style.position = 'absolute';
        honeypot.style.left = '-10000px';
        honeypot.style.opacity = '0';
        form.appendChild(honeypot);

        const actions = document.createElement('div');
        actions.className = 'aica-formular-actions';

        const btn = document.createElement('button');
        btn.type = 'submit';
        btn.className = 'aica-formular-submit';
        btn.textContent = block.submitLabel || 'Absenden';

        actions.appendChild(btn);
        form.appendChild(actions);
        body.appendChild(form);
        container.appendChild(body);

        const setCollapsed = (collapsed) => {
            container.classList.toggle('is-collapsed', collapsed);
            toggle.textContent = collapsed ? '▼' : '▲';
        };

        header.addEventListener('click', () => {
            setCollapsed(!container.classList.contains('is-collapsed'));
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            btn.disabled = true;

            const success = await this.submitContactForm(form);

            btn.disabled = false;

            if (success) {
                setCollapsed(true);
                container.classList.add('is-done');
            }
        });

        this.messagesEl.appendChild(container);
        this.scrollMessagesToEnd();

        if (!this.isRestoringHistory) {
            this.trackEvent({
                event: 'contact_form_shown',
            });
        }
    }

    /** CONTEXT / BACKEND HELPERS */

    /**
     * Gathers client context information for requests.
     * 
     * @returns {Object} The client context with origin, basePath, and fullBaseUrl.
     */
    getClientContext() {
        const { origin, pathname} = window.location;

        const parts = pathname.split('/').filter(Boolean);
        const basePath = parts.length > 0 ? `/${parts[0]}` : '';

        return {
            origin,
            basePath,
            fullBaseUrl: origin + basePath
        };
    }

    /**
     * Retrieves a context token from the backend.
     * Caches it until shortly before expiration.
     * 
     * @param {string} contextTokenUrl The URL to fetch the context token.
     * @returns {Promise<string|null>} The context token or null.
     */
    async getContextToken(contextTokenUrl) {
        this.debugLog('Getting context token from', contextTokenUrl);

        const now = Date.now();
        if (this.contextToken && now < this.contextTokenExpMs - 5000) {
            return this.contextToken;
        }

        if (!contextTokenUrl) return null;

        try {
            const res = await fetch(contextTokenUrl, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-AICA': '1',
                },
            });

            if (!res.ok) return null;

            const data = await res.json();
            if (!data?.token) return null;

            this.debugLog('Received context token', data.token);

            const payloadJson = this.decodeJwtPayload(data.token);
            this.contextTokenExpMs = payloadJson?.exp ? Number(payloadJson.exp) * 1000 : now + 30000;

            this.contextToken = data.token;
            return this.contextToken;
        } catch {
            return null;
        }
    }

    decodeJwtPayload(token) {
        if (typeof token !== 'string') return null;

        const parts = token.split('.');
        if (parts.length !== 3) return null;

        try {
            const base64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
            const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);

            return JSON.parse(atob(padded));
        } catch {
            return null;
        }
    }

    /**
     * Fetch calculated prices for product IDs from the Shopware plugin endpoint.
     * Only works for logged-in users (endpoint should return 401 otherwise).
     *
     * @param {string[]} productIds
     * @returns {Promise<Record<string, Object>>} Mapping of productId to price info.
     */
    async fetchCalculatedPrices(productIds) {
        if (!Array.isArray(productIds) || productIds.length === 0) {
            this.debugWarn('fetchCalculatedPrices called with empty productIds');
            return {};
        }

        const safeProductIds = [...new Set(
            productIds
                .filter((id) => typeof id === 'string')
                .map((id) => id.trim())
                .filter((id) => id !== '')
        )].slice(0, 50);

        if (safeProductIds.length === 0) {
            return {};
        }

        this.debugLog('Fetching calculated prices', safeProductIds);

        try {
            const res = await fetch('/aica/prices', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-AICA': '1',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ productIds: safeProductIds }),
            });

            this.debugLog('Price endpoint response status:', res.status);

            if (res.status === 401) {
                this.debugWarn('User not logged in – price endpoint returned 401');
                return {};
            }

            if (!res.ok) {
                this.debugError('Price endpoint error', res.status);
                return {};
            }

            const data = await res.json();
            this.debugLog('Price endpoint payload:', data);

            return data?.prices || {};
        } catch (e) {
            this.debugError('Price fetch failed', e);
            return {};
        }
    }

    /** TRACKING HELPERS */

    nextClientMessageIndex() {
        this.clientMessageIndex += 1;

        return this.clientMessageIndex;
    }

    trackEvent(eventPayload) {
        if (!this.trackEndpoint || !eventPayload || typeof eventPayload !== 'object') return;

        const payload = { ...eventPayload };

        fetch(this.trackEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-AICA': '1',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        }).catch((error) => {
            this.debugWarn('Tracking call failed', error);
        });
    }

    extractTokenUsage(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }

        const candidates = [
            payload.tokenUsage,
            payload.usage,
            payload.metrics?.tokenUsage,
            payload.metrics?.usage,
        ].filter((candidate) => candidate && typeof candidate === 'object');

        let input = null;
        let output = null;
        let total = null;

        for (const candidate of candidates) {
            input ??= this.pickNumber(candidate, ['input', 'inputTokens', 'input_tokens', 'promptTokens', 'prompt_tokens']);
            output ??= this.pickNumber(candidate, ['output', 'outputTokens', 'output_tokens', 'completionTokens', 'completion_tokens']);
            total ??= this.pickNumber(candidate, ['total', 'totalTokens', 'total_tokens']);
        }

        if (total == null && (input != null || output != null)) {
            total = (input ?? 0) + (output ?? 0);
        }

        if (input == null && output == null && total == null) {
            return null;
        }

        return {
            input,
            output,
            total,
        };
    }

    pickNumber(source, keys) {
        if (!source || typeof source !== 'object') {
            return null;
        }

        for (const key of keys) {
            const value = source[key];
            if (typeof value === 'number' && Number.isFinite(value)) {
                return Math.round(value);
            }
            if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
                return Math.round(Number(value));
            }
        }

        return null;
    }

    resolveLatencyMs(payload, fallbackLatencyMs) {
        const candidates = [
            payload?.latencyMs,
            payload?.latency,
            payload?.responseTimeMs,
            payload?.metrics?.latencyMs,
            payload?.metrics?.latency,
            payload?.metrics?.responseTimeMs,
        ];

        for (const candidate of candidates) {
            if (typeof candidate === 'number' && Number.isFinite(candidate)) {
                return Math.max(0, Math.round(candidate));
            }
            if (typeof candidate === 'string' && candidate.trim() !== '' && Number.isFinite(Number(candidate))) {
                return Math.max(0, Math.round(Number(candidate)));
            }
        }

        if (typeof fallbackLatencyMs === 'number' && Number.isFinite(fallbackLatencyMs)) {
            return Math.max(0, Math.round(fallbackLatencyMs));
        }

        return null;
    }

    extractModelName(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }

        if (typeof payload.model === 'string' && payload.model.trim() !== '') {
            return payload.model.trim();
        }

        if (typeof payload.llmModel === 'string' && payload.llmModel.trim() !== '') {
            return payload.llmModel.trim();
        }

        return null;
    }

    extractResponseFlags(payload) {
        const flags = {
            containsProductSuggestions: false,
            containsInfoBox: false,
            containsFormular: false,
            containsToolPayload: false,
            isError: Boolean(payload && typeof payload === 'object' && payload.error),
        };

        if (!payload || typeof payload !== 'object') {
            return flags;
        }

        const blocks = Array.isArray(payload.blocks) ? payload.blocks : [];
        for (const block of blocks) {
            if (!block || typeof block !== 'object') continue;

            if (block.kind === 'product_list') {
                flags.containsProductSuggestions = true;
            }
            if (block.kind === 'info_box') {
                flags.containsInfoBox = true;
            }
            if (block.kind === 'formular') {
                flags.containsFormular = true;
            }
            if (typeof block.kind === 'string' && block.kind.startsWith('tool')) {
                flags.containsToolPayload = true;
            }
        }

        for (const key of ['tool', 'toolResult', 'toolResults', 'toolCalls', 'toolResponse']) {
            if (Object.prototype.hasOwnProperty.call(payload, key)) {
                flags.containsToolPayload = true;
                break;
            }
        }

        return flags;
    }

    extractProductReferences(payload) {
        if (!payload || typeof payload !== 'object' || !Array.isArray(payload.blocks)) {
            return [];
        }

        const refs = [];
        for (const block of payload.blocks) {
            if (!block || typeof block !== 'object' || block.kind !== 'product_list') {
                continue;
            }

            const products = Array.isArray(block.products) ? block.products : [];
            for (const product of products) {
                if (!product || typeof product !== 'object') continue;

                const ref = {
                    source: 'assistant',
                    productId: typeof product.id === 'string' && product.id.trim() !== '' ? product.id.trim() : null,
                    productNumber: typeof product.productNumber === 'string' && product.productNumber.trim() !== '' ? product.productNumber.trim() : null,
                    productName: typeof product.name === 'string' && product.name.trim() !== '' ? product.name.trim() : null,
                };

                if (!ref.productId && !ref.productNumber && !ref.productName) {
                    continue;
                }

                refs.push(ref);
            }
        }

        return refs;
    }

    /** PERSISTENCE / SESSION STORAGE */

    /**
     * Storage key for chat history in sessionStorage.
     * 
     * @returns {string} The storage key.
     */
    storageKey() { return 'aica-history'; }

    /**
     * Storage key for chat open state in sessionStorage.
     * 
     * @returns {string} The open state key.
     */
    openKey()    { return 'aica-open'; }

    /**
     * Persists a message to sessionStorage.
     * 
     * @param {Object} m The message object to persist.
     */
    persistMessage(m) {
        const key = this.storageKey();
        const arr = this.readJson(key) || [];
        arr.push({ ...m, ts: Date.now() });

        while (arr.length > this.maxStoredMessages) {
            arr.shift();
        }

        try {
            sessionStorage.setItem(key, JSON.stringify(arr));
        } catch (e) {
            this.debugWarn('Konnte Nachricht nicht in sessionStorage schreiben:', e);
        }
    }

    /**
     * Saves the open/closed state of the chat.
     * 
     * @param {boolean} open Whether the chat is open.
     */
    saveOpenState(open) {
        sessionStorage.setItem(this.openKey(), open ? '1' : '0');
    }

    /**
     * Restores the chat history after a reload.
     */
    restoreHistory() {
        const arr = this.readJson(this.storageKey()) || [];
        this.isRestoringHistory = true;
        for (const m of arr) {
            if (m.structured && m.payload && Array.isArray(m.payload.blocks)) {
                // new, structured responses
                this.renderBlocks(m.payload.blocks);
            } else if (m.text) {
                // old, simple text history
                this.appendBubble(m.text, m.who === 'user' ? 'user' : 'bot', !!m.isError);
            }
        }
        this.isRestoringHistory = false;

        this.clientMessageIndex = arr.length;

        if ((sessionStorage.getItem(this.openKey()) || '0') === '1') {
            this.open();
        }
    }

    /**
     * Reads and parses JSON from sessionStorage.
     * 
     * @param {string} key The storage key.
     * @returns {any} The parsed JSON object or null.
     */
    readJson(key) {
        try {
            const raw = sessionStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            this.debugWarn('Konnte JSON aus sessionStorage nicht lesen:', e);
            return null;
        }
    }

    /**
     * Clears the chat history and empties the visible area.
     */
    clearHistory() {
        sessionStorage.removeItem(this.storageKey());
        if (this.messagesEl) {
            this.messagesEl.innerHTML = '';
        }
    }

     /**
     * Builds a normalized chat history for backend requests.
     *
     * The history is transformed into an array with { role, content } entries.
     * Structured bot messages are converted to a plain text fallback.
     *
     * @returns {Array<{role: string, content: string}>}
     */
    getHistoryForRequest() {
        const arr = this.readJson(this.storageKey()) || [];

        const normalized = arr
            .map((m) => {
                if (!m || typeof m !== 'object' || !m.who) {
                    return null;
                }

                const role = m.who === 'user' ? 'user' : 'assistant';

                if (typeof m.text === 'string' && m.text.trim() !== '') {
                    return { role, content: this.truncateText(m.text.trim(), this.maxHistoryEntryChars) };
                }

                if (m.structured && m.payload && Array.isArray(m.payload.blocks)) {
                    const content = this.stringifyBlocks(m.payload.blocks);
                    if (content !== '') {
                        return { role, content: this.truncateText(content, this.maxHistoryEntryChars) };
                    }
                }

                return null;
            })
            .filter(Boolean)
            .slice(-this.maxHistoryEntries);

        const bounded = [];
        let totalChars = 0;

        for (let i = normalized.length - 1; i >= 0; i -= 1) {
            const item = normalized[i];
            if (!item || typeof item.content !== 'string') {
                continue;
            }

            const nextChars = totalChars + item.content.length;
            if (nextChars > this.maxHistoryTotalChars) {
                break;
            }

            bounded.push(item);
            totalChars = nextChars;
        }

        return bounded.reverse();
    }

    truncateText(value, maxChars) {
        if (typeof value !== 'string') return '';
        if (value.length <= maxChars) return value;

        return `${value.slice(0, maxChars)}…`;
    }

    /**
     * Converts structured response blocks into a compact text representation.
     *
     * @param {Array} blocks Structured response blocks.
     * @returns {string}
     */
    stringifyBlocks(blocks) {
        if (!Array.isArray(blocks)) return '';

        return blocks
            .map((block) => {
                if (!block || typeof block !== 'object') {
                    return '';
                }

                if (typeof block.text === 'string' && block.text.trim() !== '') {
                    return block.text.trim();
                }

                if (block.kind === 'product_list' && Array.isArray(block.products)) {
                    const names = block.products
                        .map((product) => 'Art.: ' + product?.productNumber + ' ' + product?.name + ' ' + '(' + product?.purchaseUnit + ')' + 'ID: ' + product?.id)
                        .filter((name) => typeof name === 'string' && name.trim() !== '');

                    if (names.length > 0) {
                        return `${block.title ? `${block.title}: ` : ''}${names.join(', ')}`;
                    }
                }

                if (block.kind === 'info_box') {
                    const parts = [block.title, block.text]
                        .filter((part) => typeof part === 'string' && part.trim() !== '')
                        .map((part) => part.trim());

                    return parts.join(' - ');
                }

                return '';
            })
            .filter(Boolean)
            .join('\n');
    }


    /** CONTACT FORM HELPER */

    /**
     * Shows a hint to the user about the contact form if they are not logged in.
     * @returns {void}
     */
    showContactFormHint() {
        if (this._contactHintShown) return;
        this._contactHintShown = true;

        const block = {
            kind: 'info_box',
            style: 'warning',
            title: 'Hinweis Nutzer',
            text: 'Dieser Assistent kann nur allgemeine Informationen geben. Falls ihre Fragen über diese hinausgehen, werden sie ggf. gebeten ein Kontaktformular auszufüllen.',
        }

        this.renderBlock(block);
    }

    /**
     * Submits the contact form data to the backend.
     * @param {HTMLFormElement} form The contact form element.
     * @returns {Promise<boolean>} True if submission was successful, false otherwise.
     */
    async submitContactForm(form) {
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());

        try {
            const res = await fetch(this.contactEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-AICA': '1',
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            if (!res.ok) throw new Error('REQUEST_FAILED');

            this.appendBubble(
                '✅ Danke! Deine Anfrage wurde erfolgreich übermittelt.',
                'bot'
            );
            return true;
        } catch (e) {
            this.appendBubble(
                '❌ Beim Absenden ist ein Fehler aufgetreten. Bitte versuche es erneut.',
                'bot'
            );
            return false;
        } finally {
            this.scrollMessagesToEnd();
        }
    }

}
