<?php
/**
 * Retry cron — v1 PLACEHOLDER.
 *
 * Per spec D8 (Failure handling), v1 has NO automated retry on extraction
 * failure. Recovery is via the per-article "Re-extract topics" button only.
 *
 * This file exists as a placeholder so v2's cron retry pipeline lands as a
 * focused implementation rather than a new architectural decision. The v2
 * shape (queue option, exponential backoff, deactivation hook) is fully
 * specified in spec § D8 future considerations.
 *
 * v1: no hooks registered, no behavior. Class instantiation is harmless.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_Retry_Cron {

    const QUEUE_OPTION = 'ai4d_topics_retry_queue';
    const CRON_HOOK    = 'ai4d_topics_retry';

    /**
     * v1: no-op. v2 will register the WP cron event and process the queue.
     */
    public function register_hooks(): void {
        // Intentionally empty in v1.
    }

    /**
     * v1: no-op. v2 will clear the registered cron event.
     */
    public function unregister_hooks(): void {
        // Intentionally empty in v1.
    }
}
