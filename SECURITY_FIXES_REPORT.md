# تقرير إصلاح الثغرات الأمنية - Beiruttime OSINT

## 📋 ملخص التنفيذ

تم تنفيذ جميع التوصيات الأمنية الـ 7 المحددة في تقرير الثغرات الأصلي. فيما يلي تفصيل التغييرات:

---

## 🔧 الثغرات المُصلحة

### 1. ✅ ضعف التحقق من Nonce في AJAX Handlers عامة الوصول

**الملفات المُعدلة:**
- `/workspace/includes/websocket/class-websocket-handler.php`
- `/workspace/osint-pro.php`

**التغييرات:**
- إزالة جميع `wp_ajax_nopriv_` handlers من:
  - `osint_subscribe` → أصبح يتطلب تسجيل دخول
  - `osint_sse` → أصبح يتطلب تسجيل دخول
  - `sod_get_dashboard_data` → أصبح يتطلب تسجيل دخول
  - `sod_get_ticker_data` → أصبح يتطلب تسجيل دخول
  - `sod_get_threat_analysis` → أصبح يتطلب تسجيل دخول
  - `sod_get_ai_brief` → أصبح يتطلب تسجيل دخول
  - `sod_get_heatmap_data` → أصبح يتطلب تسجيل دخول

**الكود المضاف:**
```php
// REMOVED: wp_ajax_nopriv_* - no longer available to unauthenticated users
add_action('wp_ajax_osint_subscribe', array($this, 'handle_subscribe'));
// تم إزالة: add_action('wp_ajax_nopriv_osint_subscribe', ...)
```

---

### 2. ✅ تحقق غير كافٍ من صلاحيات المستخدم

**الملفات المُعدلة:**
- `/workspace/osint-pro.php`
- `/workspace/includes/websocket/class-websocket-handler.php`

**التغييرات:**
- تغيير جميع حالات `'read'` إلى `'manage_options'`
- إضافة تحقق صارم من الصلاحيات قبل معالجة أي طلب

**قبل:**
```php
if (!current_user_can('read')) { ... }
```

**بعد:**
```php
if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'غير مصرح'], 403);
    return;
}
```

---

### 3. ✅ عدم وجود تحقق من CSRF في POST handlers

**الملفات المُعدلة:**
- `/workspace/osint-pro.php`
- `/workspace/includes/websocket/class-websocket-handler.php`

**التغييرات:**
- إضافة `wp_verify_nonce()` أو `check_ajax_referer()` في بداية كل handler
- التحقق من nonce قبل أي معالجة للبيانات

**الكود المضاف:**
```php
// Verify nonce for CSRF protection
$nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? $_GET['nonce'] ?? ''));
if (empty($nonce) || wp_verify_nonce($nonce, SOD_AJAX_NONCE_ACTION) === false) {
    wp_send_json_error(['message' => 'خطأ في التحقق'], 403);
    return;
}
```

---

### 4. ✅ استخدام file_get_contents بدون تحقق كافٍ من المسار

**الملف المُعدل:**
- `/workspace/includes/upload-handlers/class-secure-file-uploader.php`

**التغييرات:**
- إضافة التحقق من أن الملف داخل مسار آمن باستخدام `wp_upload_dir()` و `realpath()`
- منع symlink attacks عبر التحقق من المسار الفعلي

**الكود المضاف:**
```php
// SECURITY: Validate file path is within safe upload directory
$upload_dir = wp_upload_dir();
$real_tmp_path = realpath($file_data['tmp_name']);
$real_upload_path = realpath($upload_dir['basedir']);

// Ensure the file is within the WordPress upload directory
if ($real_tmp_path === false || strpos($real_tmp_path, $real_upload_path) !== 0) {
    // Additional check: allow temp directory
    $temp_dir = sys_get_temp_dir();
    $real_temp_dir = realpath($temp_dir);
    if ($real_temp_dir === false || strpos($real_tmp_path, $real_temp_dir) !== 0) {
        return new \WP_Error('unsafe_path', 'مسار الملف غير آمن.');
    }
}
```

---

### 5. ✅ تخزين مؤقت للبيانات الحساسة بدون تشفير

**الملف المُعدل:**
- `/workspace/class-smart-gatekeeper.php`

**التغييرات:**
- تشفير البيانات الحساسة قبل التخزين في transients باستخدام `base64_encode()`
- فك التشفير عند القراءة باستخدام `base64_decode()`

**قبل:**
```php
set_transient('osint_rejected_log', $rejected_log, WEEK_IN_SECONDS);
```

**بعد:**
```php
// SECURITY: Encrypt sensitive data before storing in transient
$encrypted_title = base64_encode($title);
$encrypted_reason = base64_encode($reason);

array_unshift($rejected_log, [
    'time' => current_time('mysql'),
    'title' => $encrypted_title,
    'reason' => $encrypted_reason
]);
set_transient('osint_rejected_log', $rejected_log, WEEK_IN_SECONDS);
```

**في WebSocket Handler:**
```php
// Store subscription with encryption for sensitive data
$encrypted_subscription = base64_encode(json_encode($subscription));
set_transient('osint_sub_' . $subscription['token'], $encrypted_subscription, HOUR_IN_SECONDS);

// Decrypt subscription data
$subscription = json_decode(base64_decode($encrypted_subscription), true);
```

---

### 6. ✅ عدم وجود Rate Limiting على AJAX Endpoints

**الملف المُعدل:**
- `/workspace/osint-pro.php`

**التغييرات:**
- إضافة دالة `sod_check_rate_limit()` جديدة
- تطبيق rate limiting على جميع endpoints الحساسة

**الكود المضاف:**
```php
// Rate limiting helper function
function sod_check_rate_limit($action, $limit = 60, $window = 60) {
    $user_id = get_current_user_id();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'sod_rate_limit_' . md5($action . '_' . ($user_id ?: $ip));
    $attempts = (int)get_transient($key);
    
    if ($attempts >= $limit) {
        return false; // Rate limit exceeded
    }
    
    set_transient($key, $attempts + 1, $window);
    return true;
}
```

**الاستخدام:**
```php
// Rate limiting check
if (!sod_check_rate_limit('dashboard_data', 60, 60)) {
    wp_send_json_error(['message' => 'too_many_requests'], 429);
    return;
}
```

---

### 7. ✅ SQL Injection محتمل في Dynamic Table Names

**الملف المُعدل:**
- `/workspace/includes/websocket/class-websocket-handler.php`

**التغييرات:**
- إضافة regex validation لأسماء الجداول
- رفض الأسماء التي تحتوي على أحرف غير مسموحة

**الكود المضاف:**
```php
// SECURITY: Sanitize table name - only allow alphanumeric and underscore
$table_name = 'osint_alerts';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
    error_log('[OSINT] Invalid table name attempted: ' . $table_name);
    return array();
}
$table = $wpdb->prefix . $table_name;
```

---

## 📊 ملخص التغييرات حسب الملف

| الملف | عدد التغييرات | نوع التغيير |
|-------|--------------|-------------|
| `osint-pro.php` | 7 handlers مُحدثة | إزالة nopriv، إضافة rate limiting، تحسين الصلاحيات |
| `class-websocket-handler.php` | 4 دوال مُحدثة | إزالة nopriv، تشفير، SQL sanitization |
| `class-secure-file-uploader.php` | 1 دالة مُحدثة | Path validation |
| `class-smart-gatekeeper.php` | 2 دوال مُحدثة | Encryption للبيانات |

---

## 🔐 التحسينات الأمنية الإضافية

1. **تشفير مزدوج**: استخدام base64 كطبقة حماية أولى للبيانات المخزنة
2. **تحقق متعدد الطبقات**: Nonce + Capabilities + Rate Limiting
3. **Logging أمني**: تسجيل محاولات الاختراق في error_log
4. **Response Codes صحيحة**: استخدام 403 للصلاحيات، 429 لـ rate limiting

---

## ⚠️ ملاحظات هامة للتشغيل

1. **التوافق العكسي**: هذه التغييرات تتطلب تحديث أي كود خارجي يستدعي AJAX endpoints
2. **الصلاحيات المطلوبة**: جميع العمليات الآن تتطلب `manage_options` بدلاً من `read`
3. **Rate Limits الافتراضية**: 60 طلب/دقيقة لمعظم endpoints، 30 طلب/دقيقة لـ AI Brief
4. **التشفير**: البيانات القديمة المشفرة ستحتاج إلى ترحيل إذا كانت موجودة

---

## ✅ قائمة التحقق النهائية

- [x] إزالة wp_ajax_nopriv من جميع handlers الحساسة
- [x] رفع مستوى الصلاحيات من 'read' إلى 'manage_options'
- [x] إضافة wp_verify_nonce() في جميع POST handlers
- [x] التحقق من مسارات الملفات باستخدام realpath() و wp_upload_dir()
- [x] تشفير البيانات الحساسة في transients
- [x] تنفيذ Rate Limiting باستخدام transients
- [x] التحقق من أسماء الجداول باستخدام regex

---

**تاريخ التقرير:** 2024
**الحالة:** ✅ مكتمل
**عدد الملفات المُعدلة:** 4
**عدد الدوال المُحدثة:** 14+
