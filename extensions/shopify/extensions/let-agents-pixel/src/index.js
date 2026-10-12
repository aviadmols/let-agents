import { register } from '@shopify/web-pixels-extension';

/**
 * At checkout_completed: the order, so purchases of products added to the cart from the store's
 * search are counted as the search's. The visitor id is the one the storefront scripts keep
 * (cookie and localStorage let_agents_vid); without it the order is still counted, unattributed.
 */
register(({ analytics, browser, settings }) => {
  analytics.subscribe('checkout_completed', async (event) => {
    const checkout = event.data && event.data.checkout;
    const orderId = checkout && checkout.order && checkout.order.id;

    if (!checkout || !orderId || !settings.siteKey || !settings.api) {
      return;
    }

    let vid = null;
    try {
      vid = await browser.cookie.get('let_agents_vid');
    } catch (e) { /* no cookie access: try the storage */ }
    if (!vid) {
      try {
        vid = await browser.localStorage.getItem('let_agents_vid');
      } catch (e) { /* none: the order is counted, unattributed */ }
    }

    const items = (checkout.lineItems || []).map((line) => {
      const product = line.variant && line.variant.product;
      const total = line.finalLinePrice ? Number(line.finalLinePrice.amount) : Number((line.variant && line.variant.price && line.variant.price.amount) || 0) * (line.quantity || 0);

      return { product_id: String((product && product.id) || ''), quantity: line.quantity || 0, total: Number.isFinite(total) ? total : 0 };
    }).filter((item) => item.product_id !== '' && item.quantity > 0);

    if (items.length === 0) {
      return;
    }

    const body = JSON.stringify({
      order_id: String(orderId),
      total: Number((checkout.totalPrice && checkout.totalPrice.amount) || 0),
      currency: checkout.currencyCode || '',
      vid: vid || null,
      items,
    });

    try {
      // text/plain: no preflight from Shopify's sandbox.
      await fetch(String(settings.api).replace(/\/$/, '') + '/pixel/' + encodeURIComponent(settings.siteKey) + '/orders', {
        method: 'POST',
        body,
        keepalive: true,
        headers: { 'Content-Type': 'text/plain' },
      });
    } catch (e) { /* best effort: one order not counted */ }
  });
});
