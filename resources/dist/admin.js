/*
 * Sunrice Commerce Biteship: the order page's Biteship panel. Plain
 * JavaScript on Sunrice's admin API (window.Sunrice), no build step.
 * Props: { order, data } with data from BiteshipServiceProvider::panel().
 */
(function () {
    'use strict';

    function Panel(props) {
        var S = window.Sunrice;
        var h = S.React.createElement;
        var Button = S.ui.Button;
        var Badge = S.ui.Badge;
        var data = props.data || {};
        var page = S.inertia.usePage();
        var error = page.props.errors && page.props.errors.biteship;
        var busy = S.React.useState(false);

        function post(url, confirmText) {
            if (confirmText && !window.confirm(confirmText)) return;
            busy[1](true);
            S.inertia.router.post(url, {}, { preserveScroll: true, onFinish: function () { busy[1](false); } });
        }

        function row(label, value) {
            return value ? h('div', { key: label, style: { display: 'flex', justifyContent: 'space-between', gap: '1rem', fontSize: '0.875rem' } },
                h('span', { style: { opacity: 0.7 } }, label), h('span', { style: { textAlign: 'right', fontWeight: 500, wordBreak: 'break-all' } }, value)) : null;
        }

        var b = data.booking;
        var children = [];
        if (data.testing) children.push(h(Badge, { key: 'test', variant: 'outline' }, 'Testing mode: not a real pickup'));
        if (error) children.push(h('p', { key: 'err', style: { fontSize: '0.875rem', color: 'var(--destructive, #dc2626)' } }, error));
        if (b) {
            children.push(h('div', { key: 'info', style: { display: 'grid', gap: '0.25rem' } },
                row('Status', b.status_label),
                row('Courier', b.courier),
                row('Waybill', b.waybill || 'Not issued yet'),
                row('Driver', b.driver),
                row('Booking', b.order_id),
                row('Price', b.price)));
            if (b.link) children.push(h('a', { key: 'link', href: b.link, target: '_blank', rel: 'noreferrer', style: { fontSize: '0.875rem', textDecoration: 'underline' } }, 'Live tracking'));
        } else if (data.service) {
            children.push(h('p', { key: 'none', style: { fontSize: '0.875rem', opacity: 0.7 } }, 'Not booked yet. Customer chose ' + data.service + '.'));
        }
        var actions = [];
        if (data.can_book) actions.push(h(Button, { key: 'book', size: 'sm', disabled: busy[0], onClick: function () { post(data.urls.book); } }, b ? 'Book again' : 'Book pickup'));
        if (b && data.urls.label) actions.push(h(Button, { key: 'label', size: 'sm', variant: 'outline', asChild: true }, h('a', { href: data.urls.label, target: '_blank', rel: 'noreferrer' }, 'Print label')));
        if (b && data.urls.refresh) actions.push(h(Button, { key: 'refresh', size: 'sm', variant: 'outline', disabled: busy[0], onClick: function () { post(data.urls.refresh); } }, 'Refresh'));
        if (data.can_cancel) actions.push(h(Button, { key: 'cancel', size: 'sm', variant: 'outline', disabled: busy[0], onClick: function () { post(data.urls.cancel, 'Cancel the Biteship booking?'); } }, 'Cancel booking'));
        if (actions.length) children.push(h('div', { key: 'actions', style: { display: 'flex', flexWrap: 'wrap', gap: '0.5rem' } }, actions));

        if (!data.urls) return h('p', { style: { fontSize: '0.875rem', opacity: 0.7 } }, 'Not shipped with Biteship.');

        return h('div', { style: { display: 'grid', gap: '0.75rem' } }, children);
    }

    (window.SunriceCommerceQueue = window.SunriceCommerceQueue || []).push(function (api) {
        api.registerOrderPanel('biteship', Panel);
    });
})();
