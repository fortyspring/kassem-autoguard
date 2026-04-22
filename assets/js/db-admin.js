(function () {
const cfg = window.sodDbAdminConfig || {};
const ajaxUrl = cfg.ajaxurl || window.ajaxurl || '';
const nonce = cfg.nonce || '';
const REQUEST_TIMEOUT_MS = 20000;
const MIN_REANALYZE_BATCH = 5;

function post(action, extra) {
    if (!ajaxUrl) {
        return Promise.reject(new Error('missing_ajax_url'));
    }
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timeoutId = setTimeout(function () {
        if (controller) controller.abort();
    }, REQUEST_TIMEOUT_MS);
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', nonce);
    Object.keys(extra || {}).forEach(function (k) {
        fd.append(k, extra[k]);
    });
    return fetch(ajaxUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
    }).then(function (r) {
        return r.text().then(function (text) {
            clearTimeout(timeoutId);
            let parsed = null;
            try {
                parsed = JSON.parse(text);
            } catch (e) {
                throw new Error(text ? text.slice(0, 200) : ('http_' + r.status));
            }
            if (!r.ok) {
                throw new Error((parsed && parsed.data && (parsed.data.message || parsed.data.error)) || ('http_' + r.status));
            }
            return parsed;
        });
    }).catch(function (err) {
        clearTimeout(timeoutId);
        if (err && err.name === 'AbortError') {
            throw new Error('timeout');
        }
        throw err;
    });
}

function submitLegacyReanalyzeForm(reset) {
    const btn = document.querySelector('button[name="so_run_reanalyze_all"]');
    if (!btn || !btn.form) return false;
    const form = btn.form;
    const visibleBatch = document.getElementById('so-re-batch');
    const batchField = form.querySelector('input[name="so_reanalyze_batch"]');
    const resetField = form.querySelector('input[name="so_reanalyze_reset"]');
    if (batchField && visibleBatch) batchField.value = parseInt(visibleBatch.value || 20, 10);
    if (resetField) resetField.checked = !!reset;
    form.submit();
    return true;
}

function initDuplicateCleanup() {
    const runBtn = document.getElementById('so-dup-run');
    const resetBtn = document.getElementById('so-dup-reset');
    const batchEl = document.getElementById('so-dup-batch');
    if (!runBtn || !resetBtn || !batchEl) return;
    const msgEl = document.getElementById('so-dup-msg');
    const barEl = document.getElementById('so-dup-bar');
    const percentEl = document.getElementById('so-dup-percent');
    const processedEl = document.getElementById('so-dup-processed');
    const totalEl = document.getElementById('so-dup-total');
    const deletedEl = document.getElementById('so-dup-deleted');
    const statusEl = document.getElementById('so-dup-status');
    let running = false;

    function updateUI(data) {
        if (percentEl) percentEl.textContent = parseInt(data.percent || 0, 10);
        if (processedEl) processedEl.textContent = parseInt(data.processed || 0, 10);
        if (totalEl) totalEl.textContent = parseInt(data.total || 0, 10);
        if (deletedEl) deletedEl.textContent = parseInt(data.deleted_total || 0, 10);
        if (statusEl) statusEl.textContent = data.done ? 'مكتمل' : 'قيد المتابعة';
        if (barEl) barEl.style.width = parseInt(data.percent || 0, 10) + '%';
    }

    function setButtons(disabled) {
        runBtn.disabled = disabled;
        resetBtn.disabled = disabled;
    }

    function runAuto(reset) {
        if (running) return;
        running = true;
        setButtons(true);
        if (msgEl) msgEl.textContent = 'جارٍ تنظيف المكرر على دفعات متتابعة...';

        function step(firstReset) {
            post('so_ajax_duplicate_cleanup_batch', {
                batch: parseInt(batchEl.value || 50, 10),
                reset: firstReset ? 1 : 0
            }).then(function (res) {
                if (!res || !res.success) throw new Error('ajax_failed');
                updateUI(res.data || {});
                if (res.data && res.data.done) {
                    if (msgEl) msgEl.textContent = 'اكتمل تنظيف المكرر بالكامل.';
                    running = false;
                    setButtons(false);
                    return;
                }
                if (msgEl) msgEl.textContent = 'تمت معالجة دفعة جديدة... المتابعة تلقائيا';
                setTimeout(function () { step(false); }, 250);
            }).catch(function () {
                if (msgEl) msgEl.textContent = 'توقف التنظيف بسبب خطأ مؤقت. خفّض حجم الدفعة ثم أعد المحاولة.';
                running = false;
                setButtons(false);
            });
        }

        step(reset);
    }

    runBtn.addEventListener('click', function () {
        runAuto(false);
    });

    resetBtn.addEventListener('click', function () {
        if (running) return;
        if (msgEl) msgEl.textContent = 'جارٍ تصفير المؤشر...';
        post('so_ajax_duplicate_cleanup_reset', {}).then(function (res) {
            if (!res || !res.success) throw new Error('reset_failed');
            updateUI({ percent: 0, processed: 0, total: 0, deleted_total: 0, done: 0 });
            if (statusEl) statusEl.textContent = 'متوقف';
            if (msgEl) msgEl.textContent = 'تمت إعادة البدء من الصفر.';
        }).catch(function () {
            if (msgEl) msgEl.textContent = 'تعذر تصفير المؤشر.';
        });
    });
}

function initReanalyze() {
    const runBtn = document.getElementById('so-re-run');
    const resetBtn = document.getElementById('so-re-reset');
    const batchEl = document.getElementById('so-re-batch');
    if (!runBtn || !resetBtn || !batchEl) return;
    const msgEl = document.getElementById('so-re-msg');
    const barEl = document.getElementById('so-re-bar');
    const percentEl = document.getElementById('so-re-percent');
    const processedEl = document.getElementById('so-re-processed');
    const totalEl = document.getElementById('so-re-total');
    const updatedEl = document.getElementById('so-re-updated');
    const statusEl = document.getElementById('so-re-status');
    const nextEl = document.getElementById('so-re-next');
    const batchViewEl = document.getElementById('so-re-batch-view');
    let running = false;

    function currentBatch() {
        return Math.max(MIN_REANALYZE_BATCH, parseInt(batchEl.value || MIN_REANALYZE_BATCH, 10));
    }

    function updateUI(data) {
        if (percentEl) percentEl.textContent = parseInt(data.percent || 0, 10);
        if (processedEl) processedEl.textContent = parseInt(data.processed || 0, 10);
        if (totalEl) totalEl.textContent = parseInt(data.total || 0, 10);
        if (updatedEl) updatedEl.textContent = parseInt(data.updated || 0, 10);
        if (nextEl) nextEl.textContent = parseInt(data.next_offset || 0, 10);
        if (batchViewEl) batchViewEl.textContent = parseInt(data.batch || currentBatch(), 10);
        if (statusEl) statusEl.textContent = data.done ? 'مكتمل' : 'قيد المتابعة';
        if (barEl) barEl.style.width = parseInt(data.percent || 0, 10) + '%';
    }

    function setButtons(disabled) {
        runBtn.disabled = disabled;
        resetBtn.disabled = disabled;
    }

    function fallbackToDirect(reset) {
        running = false;
        setButtons(false);
        setTimeout(function () {
            submitLegacyReanalyzeForm(!!reset);
        }, 300);
    }

    function runAuto(reset) {
        if (running) return;
        running = true;
        setButtons(true);
        if (msgEl) msgEl.textContent = 'جارٍ إعادة التحليل على دفعات متتابعة...';

        function step(firstReset) {
            post('so_ajax_reanalyze_batch', {
                batch: currentBatch(),
                reset: firstReset ? 1 : 0
            }).then(function (res) {
                if (!res || !res.success) throw new Error('ajax_failed');
                updateUI(res.data || {});
                if (res.data && res.data.done) {
                    if (msgEl) msgEl.textContent = 'اكتملت إعادة التحليل الكامل.';
                    running = false;
                    setButtons(false);
                    return;
                }
                if (msgEl) msgEl.textContent = 'تمت معالجة دفعة جديدة... المتابعة تلقائيا';
                setTimeout(function () { step(false); }, 250);
            }).catch(function (err) {
                const oldBatch = currentBatch();
                if (err && err.message === 'timeout' && oldBatch > MIN_REANALYZE_BATCH) {
                    const nextBatch = Math.max(MIN_REANALYZE_BATCH, Math.floor(oldBatch / 2));
                    batchEl.value = nextBatch;
                    if (batchViewEl) batchViewEl.textContent = nextBatch;
                    if (msgEl) {
                        msgEl.textContent = 'انتهت المهلة لهذه الدفعة. تم خفض حجم الدفعة إلى ' + nextBatch + ' والمحاولة مجددا...';
                    }
                    setTimeout(function () { step(false); }, 400);
                    return;
                }
                if (msgEl) {
                    msgEl.textContent = 'تعذرت دفعة AJAX (' + ((err && err.message) ? err.message : 'unknown') + '). سيتم التحويل إلى التنفيذ المباشر.';
                }
                fallbackToDirect(false);
            });
        }

        step(!!reset);
    }

    runBtn.addEventListener('click', function (e) {
        e.preventDefault();
        runAuto(false);
    });

    resetBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (running) return;
        if (msgEl) msgEl.textContent = 'جارٍ تصفير المؤشر...';
        post('so_ajax_reanalyze_reset', {}).then(function (res) {
            if (!res || !res.success) throw new Error('reset_failed');
            updateUI({
                percent: 0,
                processed: 0,
                total: (res.data && res.data.total) ? res.data.total : 0,
                updated: 0,
                next_offset: 0,
                done: 0,
                batch: currentBatch()
            });
            if (statusEl) statusEl.textContent = 'متوقف';
            if (msgEl) msgEl.textContent = 'تمت إعادة البدء من الصفر.';
        }).catch(function () {
            if (msgEl) msgEl.textContent = 'تعذر تصفير المؤشر عبر AJAX. سيتم التحويل إلى التنفيذ المباشر.';
            fallbackToDirect(true);
        });
    });

    window.sodDbAdminRunReanalyze = function () {
        runAuto(false);
    };
    window.sodDbAdminResetReanalyze = function () {
        resetBtn.click();
    };
}

function boot() {
    initDuplicateCleanup();
    initReanalyze();
    const legacyBtn = document.querySelector('button[name="so_run_reanalyze_all"]');
    if (legacyBtn && legacyBtn.form) {
        legacyBtn.form.style.display = 'none';
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
})();
