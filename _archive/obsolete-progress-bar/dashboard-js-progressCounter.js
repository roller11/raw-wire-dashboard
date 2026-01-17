/**
 * ARCHIVED: Progress Counter UI from dashboard.js
 * 
 * This code was extracted from dashboard.js during cleanup on 2026-01-14.
 * It was one of several duplicate implementations of the progress bar/counter.
 * 
 * Other related files also archived:
 * - js/sync-manager.js (More elaborate progress system)
 * 
 * Location in original file: Lines 298-371 (approximately)
 */

    // === REAL-TIME FETCH PROGRESS UI ===
    let _fetchProgressTimer = null;

    const ensureProgressCounter = () => {
        let el = $('#rawwire-sync-counter');
        if (el.length === 0) {
            el = $('<div id="rawwire-sync-counter" style="position: fixed; bottom: 24px; right: 20px; z-index:999999; background: #fff; border:1px solid #ddd; padding:8px 12px; box-shadow:0 2px 10px rgba(0,0,0,0.12); font-weight:600;">Collected: 0</div>');
            $('body').append(el);
        }
        return el;
    };

    const updateProgressCounter = (progress) => {
        try {
            const el = ensureProgressCounter();
            const collected = typeof progress.progress !== 'undefined' ? progress.progress : 0;
            const total = typeof progress.total !== 'undefined' && progress.total > 0 ? progress.total : null;
            if (total) {
                el.text('Collected: ' + collected + ' / ' + total);
            } else {
                el.text('Collected: ' + collected);
            }
        } catch (e) {
            console.debug('updateProgressCounter error', e);
        }
    };

    const removeProgressCounter = () => {
        $('#rawwire-sync-counter').fadeOut(200, function() { $(this).remove(); });
    };

    const pollFetchProgress = (intervalMs = 1500) => {
        if (_fetchProgressTimer) return; // already polling
        const poll = () => {
            $.ajax({
                url: RawWireCfg.rest + '/fetch-progress',
                method: 'GET',
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', RawWireCfg.nonce),
                success: (resp) => {
                    // resp may be plain object with status/progress/total
                    if (!resp) return;
                    const p = resp;
                    updateProgressCounter(p);

                    // If fetch completed or no longer running, stop polling
                    if (p.status && (p.status === 'completed' || p.status === 'error' || p.status === 'none')) {
                        // Give a small delay so final counts flush
                        setTimeout(() => {
                            stopFetchProgressPoll();
                            if (p.status === 'completed') {
                                showToast('Collection complete — ' + (p.progress || 0) + ' items', 'success');
                                // Update summary counts in the UI
                                if (typeof updateSyncStatus === 'function') updateSyncStatus();
                            } else if (p.status === 'error') {
                                showToast('Collection finished with errors', 'error');
                            }
                            // Clean up counter after brief pause
                            setTimeout(removeProgressCounter, 1200);
                        }, 600);
                    }
                },
                error: () => {
                    // network hiccup - keep trying
                }
            });
        };

        // run immediately then schedule
        poll();
        _fetchProgressTimer = setInterval(poll, intervalMs);
    };

    const stopFetchProgressPoll = () => {
        if (_fetchProgressTimer) {
            clearInterval(_fetchProgressTimer);
            _fetchProgressTimer = null;
        }
    };

// Also used in sync button click handler around lines 35, 77-90:
//
// stopFetchProgressPoll();  // line 35 - on sync complete
// ensureProgressCounter();  // line 78 - on sync start
// pollFetchProgress();      // line 79 - on sync start
// stopFetchProgressPoll();  // line 89 - on sync error/cancel
// removeProgressCounter();  // line 90 - on sync error/cancel
