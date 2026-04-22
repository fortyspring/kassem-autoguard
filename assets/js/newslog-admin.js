(function () {
const cfg = window.sodNewslogConfig || {};
const nonces = cfg.nonces || {};
const ajaxUrl = cfg.ajaxurl || window.ajaxurl || '';
let currentPage = 1;
let currentItems = [];
let selectedIds = new Set();
let BANKS = {};

function esc(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function showToast(msg, color = '#16a34a', dur = 3000) {
    const t = document.getElementById('nl-toast');
    if (!t) return;
    t.textContent = msg;
    t.style.background = color;
    t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(function () {
        t.style.display = 'none';
    }, dur);
}

function nlSetStatus(message, type) {
    const box = document.getElementById('nl-status-box');
    if (!box) return;
    box.className = 'nl-status-box active';
    box.style.borderColor = type === 'error' ? '#7f1d1d' : (type === 'success' ? '#166534' : '#1e3a5f');
    box.style.background = type === 'error' ? '#2a0f14' : (type === 'success' ? '#0f2a1a' : '#0b2038');
    box.innerHTML = message;
}

function nlUpdateStats(stats, total) {
    const filtered = document.querySelector('#nl-filtered-count b');
    const classified = document.querySelector('#nl-classified-count b');
    const unclassified = document.querySelector('#nl-unclassified-count b');
    const manual = document.querySelector('#nl-manual-count b');
    const hybrid = document.querySelector('#nl-hybrid-count b');
    if (filtered) filtered.textContent = (total || 0).toLocaleString('ar');
    if (classified) classified.textContent = ((stats && stats.classified) || 0).toLocaleString('ar');
    if (unclassified) unclassified.textContent = ((stats && stats.unclassified) || 0).toLocaleString('ar');
    if (manual) manual.textContent = ((stats && stats.manual_locked) || 0).toLocaleString('ar');
    if (hybrid) hybrid.textContent = ((stats && stats.hybrid_ready) || 0).toLocaleString('ar');
}

function apiFetch(data) {
    if (!ajaxUrl) {
        return Promise.reject(new Error('ajaxurl missing'));
    }
    return fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data),
        credentials: 'same-origin'
    }).then(function (r) {
        return r.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                const trimmed = String(text || '').trim();
                const objStart = trimmed.indexOf('{');
                const arrStart = trimmed.indexOf('[');
                let start = -1;
                if (objStart >= 0 && arrStart >= 0) start = Math.min(objStart, arrStart);
                else start = objStart >= 0 ? objStart : arrStart;
                if (start >= 0) {
                    const candidate = trimmed.slice(start);
                    try {
                        return JSON.parse(candidate);
                    } catch (e2) {}
                }
                throw new Error(text ? text.slice(0, 220) : ('HTTP ' + r.status));
            }
        });
    });
}

function sodBankCanon(bank) {
    const map = {
        actor: 'actors',
        type: 'types',
        level: 'levels',
        region: 'regions',
        target: 'targets',
        context: 'contexts',
        intent: 'intents',
        weapon: 'weapons'
    };
    return map[bank] || bank;
}

const BANK_SELECT_MAP = {
    types: 'nl-edit-type',
    levels: 'nl-edit-level',
    regions: 'nl-edit-region',
    actors: 'nl-edit-actor',
    targets: 'nl-edit-target',
    contexts: 'nl-edit-context',
    intents: 'nl-edit-intent',
    weapons: 'nl-edit-weapon'
};

function refillSelect(id, values, keepValue) {
    const el = document.getElementById(id);
    if (!el) return;
    const current = keepValue || el.value || '';
    el.innerHTML = '';
    el.appendChild(new Option('-- اختر --', ''));
    (values || []).forEach(function (v) {
        el.appendChild(new Option(v, v));
    });
    if (!current) return;
    for (const o of el.options) {
        if (o.value === current || o.text === current) {
            el.value = o.value;
            return;
        }
    }
    el.appendChild(new Option(current, current));
    el.value = current;
}

function refreshBankSelects(keep) {
    const kv = keep || {};
    Object.entries(BANK_SELECT_MAP).forEach(function (entry) {
        const bankKey = entry[0];
        const selectId = entry[1];
        refillSelect(selectId, BANKS[bankKey] || [], kv[bankKey] || '');
    });
}

function loadBanks(cb) {
    apiFetch({
        action: 'sod_newslog_get_banks',
        nonce: nonces.search
    }).then(function (d) {
        if (!d.success) {
            showToast('تعذّر جلب البنوك', '#dc2626');
            nlSetStatus('تعذر تحميل بنوك التصنيف.', 'error');
            return;
        }
        BANKS = d.data || {};
        refreshBankSelects();
        if (cb) cb();
    }).catch(function (err) {
        nlSetStatus('تعذر تحميل بنوك التصنيف: <strong>' + esc(err && err.message ? err.message : 'unknown') + '</strong>', 'error');
        showToast('تعذّر جلب البنوك', '#dc2626');
    });
}

let _dbt = null;
window.nlDebounce = function () {
    clearTimeout(_dbt);
    _dbt = setTimeout(function () {
        window.nlLoad(1);
    }, 400);
};

function nlExtractRelatedQuery(title) {
    const stopWords = ['في', 'من', 'على', 'عن', 'إلى', 'الى', 'مع', 'هذا', 'هذه', 'ذلك', 'تلك', 'بعد', 'قبل', 'حول', 'بين', 'أمام', 'خلال', 'التي', 'الذي', 'كما', 'عند', 'تم', 'لقد', 'ثم', 'قال', 'أكد', 'أفاد', 'نقل', 'صورة', 'صور', 'خبر'];
    const tokens = String(title || '').replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).map(function (s) {
        return s.trim();
    }).filter(Boolean);
    const filtered = tokens.filter(function (t) {
        return t.length >= 3 && stopWords.indexOf(t) === -1;
    });
    return filtered.slice(0, 4).join(' ');
}

window.nlShowRelatedNews = function (title) {
    const q = nlExtractRelatedQuery(title) || String(title || '').trim();
    const input = document.getElementById('nl-q');
    if (input) input.value = q;
    nlSetStatus('تمت تصفية سجل الأخبار حسب العنوان المرتبط: <strong>' + esc(q) + '</strong>', 'info');
    window.nlLoad(1);
};

function renderPager(total, page, perPage) {
    const wrap = document.getElementById('nl-pager');
    if (!wrap) return;
    const pages = Math.ceil(total / perPage);
    if (pages <= 1) {
        wrap.innerHTML = '';
        return;
    }
    let html = '';
    const start = Math.max(1, page - 3);
    const end = Math.min(pages, page + 3);
    if (start > 1) html += '<button class="nl-page-btn" onclick="nlLoad(1)">«</button>';
    for (let i = start; i <= end; i++) {
        html += '<button class="nl-page-btn' + (i === page ? ' active' : '') + '" onclick="nlLoad(' + i + ')">' + i + '</button>';
    }
    if (end < pages) html += '<button class="nl-page-btn" onclick="nlLoad(' + pages + ')">»</button>';
    wrap.innerHTML = html;
}

function nlUpdateBulkBar() {
    const bar = document.getElementById('nl-bulk-bar');
    const cnt = document.getElementById('nl-sel-count');
    if (bar) bar.style.display = selectedIds.size > 0 ? 'flex' : 'none';
    if (cnt) cnt.textContent = selectedIds.size + ' محدد';
    const sa = document.getElementById('nl-select-all');
    if (!sa) return;
    const all = document.querySelectorAll('.nl-row-check');
    sa.checked = all.length > 0 && selectedIds.size === all.length;
    sa.indeterminate = selectedIds.size > 0 && selectedIds.size < all.length;
}

window.nlToggleAll = function (checked) {
    document.querySelectorAll('.nl-row-check').forEach(function (cb) {
        cb.checked = checked;
        const id = parseInt(cb.value, 10);
        if (checked) selectedIds.add(id);
        else selectedIds.delete(id);
    });
    nlUpdateBulkBar();
};

window.nlClearSelection = function () {
    selectedIds.clear();
    document.querySelectorAll('.nl-row-check').forEach(function (cb) {
        cb.checked = false;
    });
    const sa = document.getElementById('nl-select-all');
    if (sa) {
        sa.checked = false;
        sa.indeterminate = false;
    }
    nlUpdateBulkBar();
};

function nlRowCheck(cb, id) {
    if (cb.checked) selectedIds.add(parseInt(id, 10));
    else selectedIds.delete(parseInt(id, 10));
    nlUpdateBulkBar();
}

window.nlRowCheck = nlRowCheck;

window.nlBulkApply = function () {
    if (!selectedIds.size) {
        showToast('لم يتم تحديد أي خبر', '#f59e0b');
        return;
    }
    const action = (document.getElementById('nl-bulk-action') || {}).value || '';
    if (!action) {
        showToast('اختر إجراء أولًا', '#f59e0b');
        return;
    }
    if (action === 'delete' && !window.confirm('سيتم حذف ' + selectedIds.size + ' خبر نهائيًا. هل أنت متأكد؟')) return;
    apiFetch({
        action: 'sod_newslog_bulk',
        nonce: nonces.bulk,
        bulk_action: action,
        ids: Array.from(selectedIds).join(',')
    }).then(function (d) {
        if (!d.success) {
            showToast('فشل: ' + (d.data || ''), '#dc2626');
            return;
        }
        const n = d.data.deleted ?? d.data.updated ?? 0;
        showToast('تم ' + (action === 'delete' ? 'حذف' : 'تحديث') + ' ' + n + ' خبر');
        selectedIds.clear();
        window.nlLoad(currentPage);
    }).catch(function () {
        showToast('تعذّر تنفيذ العملية الجماعية', '#dc2626');
    });
};

window.nlAddToBank = function (bank) {
    const label = bank === 'actor' ? 'الجهة الفاعلة' : 'القيمة';
    const val = window.prompt('أدخل ' + label + ' الجديد:', '');
    if (!val || !val.trim()) return;
    apiFetch({
        action: 'sod_newslog_add_to_bank',
        nonce: nonces.bank,
        bank: bank,
        value: val.trim()
    }).then(function (d) {
        if (!d.success) {
            showToast('فشل: ' + (d.data || ''), '#dc2626');
            return;
        }
        BANKS = d.data.banks || BANKS;
        const keep = {};
        keep[sodBankCanon(bank)] = val.trim();
        refreshBankSelects(keep);
        showToast('أُضيف "' + val.trim() + '" للبنك');
    }).catch(function () {
        showToast('تعذّر الإضافة', '#dc2626');
    });
};

window.nlRemoveFromBank = function (bank, selectId) {
    const sel = document.getElementById(selectId);
    const val = sel ? String(sel.value || '').trim() : '';
    if (!val) {
        showToast('اختر قيمة للحذف', '#f59e0b');
        return;
    }
    if (!window.confirm('حذف "' + val + '" من البنك؟')) return;
    apiFetch({
        action: 'sod_newslog_remove_from_bank',
        nonce: nonces.bank,
        bank: bank,
        value: val
    }).then(function (d) {
        if (!d.success) {
            showToast('فشل الحذف: ' + (d.data || ''), '#dc2626');
            return;
        }
        BANKS = d.data.banks || BANKS;
        refreshBankSelects();
        showToast('تم حذف القيمة من البنك');
    }).catch(function () {
        showToast('تعذّر الحذف', '#dc2626');
    });
};

function renderHybridBadges(item) {
    const rawLayers = Array.isArray(item.hybrid_layers) ? item.hybrid_layers : [];
    const layers = rawLayers.filter(function (x) {
        return x && String(x).trim() && String(x).trim() !== '—';
    }).slice(0, 4);
    if (layers.length) {
        return layers.map(function (layer) {
            return '<span class="nl-hybrid-chip">' + esc(String(layer)) + '</span>';
        }).join('');
    }
    const fallback = item.hybrid_label && String(item.hybrid_label).trim() ? String(item.hybrid_label) : '';
    if (!fallback || fallback === '—' || fallback === '0') return '<span class="nl-empty-state">—</span>';
    return fallback.split('+').map(function (layer) {
        const clean = String(layer).trim();
        return clean ? '<span class="nl-hybrid-chip">' + esc(clean) + '</span>' : '';
    }).join('') || '<span class="nl-empty-state">—</span>';
}

function renderEvaluationBadge(item) {
    const raw = item.evaluation_mode && String(item.evaluation_mode).trim() ? String(item.evaluation_mode).trim() : '';
    const label = item.evaluation_label && String(item.evaluation_label).trim() ? String(item.evaluation_label).trim() : '—';
    let cls = 'nl-state-auto';
    if (raw === 'manual_override' || raw === 'manual_locked' || label.indexOf('مقفل') !== -1) cls = 'nl-state-locked';
    else if (raw === 'manual_saved' || label.indexOf('يدوي') !== -1) cls = 'nl-state-manual';
    return '<span class="nl-state-chip ' + cls + '">' + esc(label) + '</span>';
}

function renderTable(items) {
    const tb = document.getElementById('nl-tbody');
    if (!tb) return;
    if (!items.length) {
        tb.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:20px;color:#5a7a9a;">لا توجد نتائج</td></tr>';
        return;
    }
    tb.innerHTML = items.map(function (e) {
        const sc = parseInt(e.score || 0, 10);
        const cls = sc >= 200 ? 'nl-score-crit' : (sc >= 140 ? 'nl-score-high' : (sc >= 80 ? 'nl-score-med' : 'nl-score-low'));
        const dt = e.event_timestamp ? new Date(e.event_timestamp * 1000).toLocaleString('ar', { year: '2-digit', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
        const learned = e.has_learning ? '<span class="nl-learning-badge">✓ تعلّم</span>' : '';
        const chk = selectedIds.has(parseInt(e.id, 10)) ? 'checked' : '';
        const chips = [
            ['استراتيجي', e.intel_type],
            ['تكتيكي', e.tactical_level],
            ['منطقة', e.region],
            ['فاعل', e.actor_v2],
            ['هدف', e.target_v2],
            ['سياق', e.context_actor],
            ['نية', e.intent],
            ['سلاح', e.weapon_v2]
        ].filter(function (x) {
            return x[1] && String(x[1]).trim() && String(x[1]).trim() !== 'غير محدد' && String(x[1]).trim() !== '—';
        }).map(function (x) {
            return '<span class="nl-mini-chip"><b>' + esc(x[0]) + ':</b> ' + esc(String(x[1]).substring(0, 42)) + '</span>';
        }).join('');
        return '<tr id="nl-row-' + e.id + '">' +
            '<td style="text-align:center;"><input type="checkbox" class="nl-row-check" value="' + e.id + '" ' + chk + ' style="cursor:pointer;accent-color:#2563eb;width:14px;height:14px;" onchange="nlRowCheck(this,' + e.id + ')"></td>' +
            '<td style="color:#5a7a9a;font-size:11px;white-space:nowrap;">' + dt + '</td>' +
            '<td style="max-width:420px;">' +
            '<a href="#" class="nl-title-link" data-related="' + encodeURIComponent(String(e.title || '')) + '" onclick="event.preventDefault();nlShowRelatedNews(decodeURIComponent(this.dataset.related||\'\'))" title="' + esc(e.title) + '">' + esc(String(e.title || '').substring(0, 100)) + (String(e.title || '').length > 100 ? '...' : '') + '</a>' + learned +
            (chips ? '<div class="nl-meta-stack">' + chips + '</div>' : '') +
            '</td>' +
            '<td><span class="nl-badge">' + esc(e.source_name || '—') + '</span></td>' +
            '<td style="font-size:11px;">' + esc(e.intel_type || '—') + '</td>' +
            '<td style="font-size:11px;">' + esc(e.region || '—') + '</td>' +
            '<td style="font-size:11px;color:#94a3b8;" title="' + esc(e.actor_v2 || '—') + '">' + esc(e.actor_v2 || '—') + '</td>' +
            '<td style="font-size:11px;line-height:1.8;">' + renderHybridBadges(e) + '</td>' +
            '<td style="font-size:11px;">' + renderEvaluationBadge(e) + '</td>' +
            '<td><span class="nl-score ' + cls + '">' + sc + '</span></td>' +
            '<td style="white-space:nowrap;"><button class="nl-btn nl-btn-primary nl-btn-sm" onclick="nlOpenEditById(' + e.id + ')">✏️</button> <button class="nl-btn nl-btn-success nl-btn-sm" onclick="nlReclassifyOneDirect(' + e.id + ')" title="إعادة تصنيف">⚙️</button></td>' +
            '</tr>';
    }).join('');
}

window.nlLoad = function (p, options) {
    options = options || {};
    currentPage = p || 1;
    const tb = document.getElementById('nl-tbody');
    if (tb) tb.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:20px;color:#5a7a9a;">جاري التحميل...</td></tr>';
    apiFetch({
        action: 'sod_newslog_search',
        nonce: nonces.search,
        q: (document.getElementById('nl-q') || {}).value || '',
        region: (document.getElementById('nl-region') || {}).value || '',
        actor: (document.getElementById('nl-actor') || {}).value || '',
        intel_type: (document.getElementById('nl-type') || {}).value || '',
        score: (document.getElementById('nl-score') || {}).value || '',
        evaluation_state: (document.getElementById('nl-evaluation') || {}).value || '',
        manual_state: (document.getElementById('nl-manual-filter') || {}).value || '',
        hybrid_state: (document.getElementById('nl-hybrid-filter') || {}).value || '',
        page: currentPage,
        per_page: (document.getElementById('nl-per-page') || {}).value || '25'
    }).then(function (d) {
        if (!d.success) {
            nlSetStatus('فشل تحميل سجل الأخبار: <strong>' + esc(typeof d.data === 'string' ? d.data : JSON.stringify(d.data || {})) + '</strong>', 'error');
            showToast('خطأ في التحميل', '#dc2626');
            return;
        }
        currentItems = d.data.items || [];
        renderTable(currentItems);
        renderPager(d.data.total, d.data.page, d.data.per_page);
        nlUpdateStats(d.data.stats || {}, d.data.total || 0);
        if (!options.preserveStatus) {
            nlSetStatus('تم تحميل سجل الأخبار بنجاح. النتائج الحالية: <strong>' + ((d.data.total) || 0).toLocaleString('ar') + '</strong>', 'success');
        }
    }).catch(function (err) {
        nlSetStatus('تعذر تحميل سجل الأخبار: <strong>' + esc(err && err.message ? err.message : 'unknown') + '</strong>', 'error');
        showToast('تعذّر الاتصال', '#dc2626');
    });
};

function setSelect(id, val) {
    const el = document.getElementById(id);
    if (!el) return;
    for (const o of el.options) {
        if (o.value === val || o.text === val) {
            el.value = o.value;
            return;
        }
    }
}

window.nlOpenEditById = function (id) {
    const e = currentItems.find(function (x) {
        return +x.id === +id;
    });
    if (!e) {
        showToast('لم يُعثر على العنصر', '#dc2626');
        return;
    }
    window.nlOpenEdit(e);
};

window.nlOpenEdit = function (e) {
    let wd = {};
    try {
        wd = JSON.parse(e.war_data || '{}') || {};
    } catch (x) {
        wd = {};
    }
    document.getElementById('nl-edit-id').value = e.id;
    document.getElementById('nl-edit-title').value = e.title || '';
    document.getElementById('nl-edit-score').value = e.score || 0;
    refreshBankSelects({
        types: e.intel_type || '',
        levels: e.tactical_level || '',
        regions: e.region || '',
        actors: e.actor_v2 || '',
        weapons: wd.weapon_means || e.weapon_v2 || '',
        targets: wd.target || e.target_v2 || '',
        contexts: wd.context_actor || e.context_actor || '',
        intents: wd.intent || e.intent || ''
    });
    setSelect('nl-edit-status', e.status || 'published');
    const evalEl = document.getElementById('nl-edit-evaluation');
    if (evalEl) {
        evalEl.textContent = e.manual_locked ? 'يدوي مقفل — لن يُعاد تصنيفه آليًا' : ('آخر تقييم: ' + (e.evaluation_label || 'آلي'));
        evalEl.style.display = 'block';
    }
    const lockEl = document.getElementById('nl-edit-manual-lock');
    if (lockEl) lockEl.checked = true;
    const reBtn = document.querySelector('#nl-modal .nl-btn-success');
    if (reBtn) {
        reBtn.disabled = !!e.manual_locked;
        reBtn.style.opacity = e.manual_locked ? '0.55' : '1';
        reBtn.title = e.manual_locked ? 'هذا الخبر مقفل يدويًا' : 'إعادة التصنيف الآلي لهذا الخبر';
    }
    const metaBox = document.getElementById('nl-edit-meta');
    if (metaBox) {
        metaBox.innerHTML = (e.manual_locked ? '<strong>🔒 مقفل يدويًا:</strong> أي حفظ هنا يعلّم النظام ويمنع الكتابة الآلية فوق هذا الخبر.' : '<strong>ℹ️ وضع الخبر:</strong> بعد الحفظ اليدوي سيتعلم النظام من هذا الخبر ويقفل حقوله من إعادة التصنيف الآلي.') + '<br><span style="color:#334155">المصدر: ' + esc(e.source_name || '—') + ' — التقييم: ' + esc(e.evaluation_label || 'آلي') + '</span>';
    }
    const modal = document.getElementById('nl-modal');
    if (modal) modal.classList.add('open');
};

window.nlCloseModal = function () {
    const modal = document.getElementById('nl-modal');
    if (modal) modal.classList.remove('open');
};

window.nlSaveItem = function () {
    const id = document.getElementById('nl-edit-id').value;
    nlSetStatus('جارٍ حفظ التعديل اليدوي...', 'info');
    apiFetch({
        action: 'sod_newslog_save',
        nonce: nonces.save,
        id: id,
        title: document.getElementById('nl-edit-title').value,
        intel_type: document.getElementById('nl-edit-type').value,
        tactical_level: document.getElementById('nl-edit-level').value,
        region: document.getElementById('nl-edit-region').value,
        actor_v2: document.getElementById('nl-edit-actor').value,
        score: document.getElementById('nl-edit-score').value,
        status: document.getElementById('nl-edit-status').value,
        weapon_v2: document.getElementById('nl-edit-weapon').value,
        target_v2: document.getElementById('nl-edit-target').value,
        context_actor: document.getElementById('nl-edit-context').value,
        intent: document.getElementById('nl-edit-intent').value,
        manual_lock: (document.getElementById('nl-edit-manual-lock') || {}).checked ? '1' : ''
    }).then(function (d) {
        if (!d.success) {
            const err = typeof d.data === 'string' ? d.data : JSON.stringify(d.data || {});
            nlSetStatus('فشل حفظ التعديل اليدوي: ' + err, 'error');
            showToast('فشل الحفظ: ' + err, '#dc2626', 7000);
            return;
        }
        nlSetStatus('تم حفظ التعديل اليدوي بنجاح، وتم حفظ الخبر في التعلم وقفلُه من أي تعديل آلي لاحق.', 'success');
        showToast('تم الحفظ والتعلم والقفل بنجاح');
        window.nlCloseModal();
        window.nlLoad(currentPage, { preserveStatus: true });
    }).catch(function () {
        nlSetStatus('تعذر الاتصال أثناء حفظ التعديل اليدوي.', 'error');
        showToast('تعذر تنفيذ الحفظ', '#dc2626', 7000);
    });
};

window.nlReclassifyOne = function () {
    const id = document.getElementById('nl-edit-id').value;
    window.nlReclassifyOneDirect(id);
};

window.nlReclassifyOneDirect = function (id) {
    nlSetStatus('جارٍ إعادة تصنيف الخبر المحدد...', 'info');
    apiFetch({
        action: 'sod_newslog_reclassify',
        nonce: nonces.reclassify,
        id: id,
        mode: 'single'
    }).then(function (d) {
        if (!d.success) {
            const raw = typeof d.data === 'string' ? d.data : JSON.stringify(d.data || {});
            const err = raw.indexOf('manual_locked') !== -1 ? 'هذا الخبر مقفل يدويًا ولن يُعاد تصنيفه آليًا.' : raw;
            nlSetStatus('فشلت إعادة التصنيف: ' + err, 'error');
            showToast('فشل إعادة التصنيف: ' + err, '#dc2626', 7000);
            return;
        }
        nlSetStatus('اكتملت إعادة التصنيف للخبر المحدد بنجاح.', 'success');
        showToast('أعيد التصنيف بنجاح');
        window.nlCloseModal();
        window.nlLoad(currentPage);
    }).catch(function () {
        nlSetStatus('تعذر تنفيذ إعادة التصنيف لهذا الخبر.', 'error');
        showToast('تعذر تنفيذ إعادة التصنيف', '#dc2626', 7000);
    });
};

window.nlBulkReclassify = function () {
    if (!selectedIds.size) {
        showToast('لم تُحدد أي عناصر', '#f59e0b');
        return;
    }
    if (!window.confirm('سيتم إعادة التصنيف الآلي لـ ' + selectedIds.size + ' خبر. هل تريد المتابعة؟')) return;
    const ids = Array.from(selectedIds);
    let done = 0;
    let errors = 0;
    showToast('جارٍ إعادة التصنيف...', '#2563eb', 8000);
    function next() {
        if (!ids.length) {
            showToast('أُعيد تصنيف ' + done + ' — أخطاء: ' + errors, '#16a34a', 4000);
            window.nlClearSelection();
            window.nlLoad(currentPage, { preserveStatus: true });
            return;
        }
        const id = ids.shift();
        apiFetch({
            action: 'sod_newslog_reclassify',
            nonce: nonces.reclassify,
            id: id,
            mode: 'single'
        }).then(function (d) {
            if (d.success) done++;
            else errors++;
            next();
        }).catch(function () {
            errors++;
            next();
        });
    }
    next();
};

window.nlReclassifyAll = function () {
    if (!window.confirm('سيتم إعادة تصنيف جميع الأخبار. هذه العملية قد تستغرق وقتًا. هل تريد المتابعة؟')) return;
    let offset = 0;
    let totalUpdated = 0;
    let totalSkipped = 0;
    const batch = 50;
    nlSetStatus('بدأت إعادة تصنيف كل الأخبار على دفعات AJAX. 0%', 'info');
    showToast('جارٍ إعادة التصنيف...', '#2563eb', 5000);
    function step() {
        apiFetch({
            action: 'sod_newslog_reclassify',
            nonce: nonces.reclassify,
            mode: 'all',
            offset: offset,
            batch: batch
        }).then(function (d) {
            if (!d.success) {
                const err = typeof d.data === 'string' ? d.data : JSON.stringify(d.data || {});
                nlSetStatus('فشلت إعادة تصنيف الكل: ' + err, 'error');
                showToast('فشل إعادة التصنيف: ' + err, '#dc2626', 7000);
                return;
            }
            const data = d.data || {};
            totalUpdated += parseInt(data.updated || 0, 10);
            totalSkipped += parseInt(data.skipped || 0, 10);
            offset = parseInt(data.next_offset || 0, 10);
            nlSetStatus('إعادة التصنيف قيد التنفيذ: <strong>' + ((data.percent) || 0).toLocaleString('ar') + '%</strong> — تمت معالجة <strong>' + ((data.processed) || 0).toLocaleString('ar') + '</strong> من <strong>' + ((data.total) || 0).toLocaleString('ar') + '</strong> خبر.', 'info');
            if (!data.done) {
                setTimeout(step, 80);
                return;
            }
            const s = data.stats || {};
            nlSetStatus('اكتملت إعادة التصنيف. الأخبار التي أُعيدت معالجتها: <strong>' + (totalUpdated || 0).toLocaleString('ar') + '</strong>، وتم تخطي المقفل يدويًا: <strong>' + (totalSkipped || 0).toLocaleString('ar') + '</strong>. المصنّف الآن: <strong>' + ((s.classified_after) || 0).toLocaleString('ar') + '</strong>، وغير المحسوم: <strong>' + ((s.unclassified_after) || 0).toLocaleString('ar') + '</strong>.', 'success');
            showToast('اكتملت إعادة التصنيف بنجاح');
            window.nlLoad(currentPage, { preserveStatus: true });
        }).catch(function () {
            nlSetStatus('تعذر تنفيذ إعادة تصنيف الكل بسبب خطأ اتصال أو مهلة.', 'error');
            showToast('تعذر تنفيذ إعادة التصنيف', '#dc2626', 7000);
        });
    }
    step();
};

function bootNewslogAdmin() {
    const modal = document.getElementById('nl-modal');
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === this) window.nlCloseModal();
        });
    }
    nlSetStatus('جارٍ تحميل بنوك التصنيف وسجل الأخبار...', 'info');
    loadBanks(function () {
        window.nlLoad(1);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootNewslogAdmin);
} else {
    bootNewslogAdmin();
}
})();
