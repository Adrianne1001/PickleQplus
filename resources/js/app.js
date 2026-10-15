/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

// Registered first, so the 404/410 hook still works if Echo fails to start.
import './guest-expiry';

import './echo';

import './public-pages';
