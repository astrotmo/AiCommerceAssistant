/**
 * Main JavaScript entry point for the AICA - AI Commerce Assistant plugin.
 * 
 * This file registers the AICA plugin with the Shopware PluginManager,
 * enabling the chat functionality on storefront pages.
 * 
 * @package    AiCommerceAssistant
 * @author     Paul Nöth
 */

import AicaPlugin from './plugin/aica.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('AicaPlugin', AicaPlugin, '[data-aica-root]');
