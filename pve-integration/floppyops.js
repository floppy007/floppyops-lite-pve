/**
 * FloppyOps Lite — PVE Toolbar Button
 *
 * Adds a "FloppyOps" button to PVE's top toolbar.
 * Opens Lite in a new tab with PVE SSO ticket.
 */
(function() {
    'use strict';

    Ext.onReady(function() {
        var poll = setInterval(function() {
            // Find PVE's top toolbar (the header bar with logout, help, etc.)
            var toolbar = Ext.ComponentQuery.query('toolbar[cls~=x-toolbar-pve-header]')[0]
                || Ext.ComponentQuery.query('#pveTopToolbar')[0]
                || Ext.ComponentQuery.query('toolbar')[0];

            // Fallback: find the toolbar that contains the help/logout buttons
            if (!toolbar) {
                var btns = Ext.ComponentQuery.query('button[text=Help]');
                if (btns.length) toolbar = btns[0].up('toolbar');
            }
            if (!toolbar) {
                btns = Ext.ComponentQuery.query('button[text=Hilfe]');
                if (btns.length) toolbar = btns[0].up('toolbar');
            }

            if (!toolbar) return;
            clearInterval(poll);

            // Don't add twice
            if (toolbar.down('#floppyopsBtn')) return;

            // Insert before the rightmost items (help/logout)
            toolbar.insert(toolbar.items.length - 2, {
                xtype: 'button',
                itemId: 'floppyopsBtn',
                iconCls: 'fa fa-shield',
                text: 'FloppyOps',
                tooltip: 'FloppyOps Lite',
                style: 'margin-right:6px',
                handler: function() {
                    var ticket = Ext.util.Cookies.get('PVEAuthCookie') || '';
                    var url = window.location.origin + '/floppyops/?pve_ticket=' + encodeURIComponent(ticket);
                    window.open(url, '_blank');
                }
            });
        }, 500);
    });
})();
