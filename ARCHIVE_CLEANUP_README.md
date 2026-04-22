# أداة تنظيف أرشيف الفاعلين - OSINT-LB PRO

## نظرة عامة

أداة متقدمة لتنظيف الأرشيف السابق من الفاعلين الملوثين أو غير المحددين. تعيد الأداة تحليل الأحداث القديمة باستخدام المحرك الموحد `sod_resolve_actor_final` مع مراعاة السياق الإقليمي لتحديد الفاعل بدقة أكبر.

## المميزات

- 🔍 **كشف تلقائي** للأحداث الملوثة (فاعل غير محسوم، مجهول، وكالات إعلامية)
- 🔄 **إعادة تحليل ذكية** باستخدام المحرك الموحد V3 + V2 fallback
- ✅ **نظام تحقق** لمنع استبدال فاعل جيد بآخر سيء
- 📊 **سجل كامل** لجميع عمليات التنظيف
- 🚀 **عمل دفعات** لتجنب الضغط على الخادم
- 🎯 **تحديث شامل** لجميع الحقول (actor_final, war_data, field_data, 5w1h)

## طرق الاستخدام

### 1. الواجهة الإدارية (WordPress Admin)

1. اذهب إلى **OSINT Events → تنظيف الأرشيف**
2. اختر حجم الدفعة (10-200 حدث)
3. اضغط على **بدء تنظيف الأرشيف**
4. تابع التقدم في الوقت الحقيقي
5. راجع سجل العمليات والنتائج

### 2. سطر الأوامر (WP-CLI)

```bash
# عد الأحداث الملوثة
wp so-cleanup-archive count

# عرض إحصائيات التنظيف
wp so-cleanup-archive stats

# تنظيف 100 حدث
wp so-cleanup-archive --limit=50 --max=100

# تشغيل تجريبي دون تغييرات
wp so-cleanup-archive --dry-run --max=10

# تصدير النتائج كـ JSON
wp so-cleanup-archive --format=json > results.json

# تنظيف مع تحديد نقطة البداية
wp so-cleanup-archive --offset=100 --limit=50
```

### 3. برمجياً (PHP API)

```php
// عد الأحداث الملوثة
$dirty_count = so_count_dirty_events();

// تنظيف دفعة واحدة
$result = so_cleanup_archive_batch(50, 0);

// الحصول على الإحصائيات
$stats = so_get_cleanup_stats();

// تنظيف حدث واحد محدد
$single_result = SO_Archive_Cleanup_Tool::clean_single_event($event_id);
```

## الفاعلون الملوثون المستهدفون

تستهدف الأداة الأنواع التالية من الفاعلين:

### فاعلون غير محددين
- "فاعل غير محسوم"
- "غير معروف"
- "مجهول"
- "unknown"
- "unidentified"

### وكالات إعلامية (لا يجب أن تكون فاعلين)
- رويترز، فرانس برس، أسوشيتد برس
- الأناضول، العربية، الجزيرة
- CNN, BBC, Sky News
- أي نص يحتوي على "وكالة"، "صحيفة"، "قناة"

### أنماط أخرى
- نصوص تبدأ بـ "according to", "said by"
- أي فاعل تم تحديده كمصدر إعلامي

## آلية العمل

### 1. الكشف
```
الأحداث الملوثة → استخراج العنوان والمحتوى والمنطقة
```

### 2. إعادة التحليل
```
sod_resolve_actor_final(title, content, region)
    ↓
ActorDecisionEngineV3 (الأولوية)
    ↓
Fallback إلى V2 إذا لزم الأمر
```

### 3. التحقق
```
النتيجة الجديدة → هل هي فاعل صالح؟
    ↓ نعم
هل هي أفضل من القديمة؟
    ↓ نعم
تحديث البيانات
```

### 4. التحديث
```
field_data:
  - actor_final
  - actor_v2
  - actor_source
  - actor_reason
  - actor_confidence

war_data:
  - actor
  - who
  - who_primary

5w1h:
  - who_primary
```

### 5. التسجيل
```
جدول wp_so_cleanup_log:
  - event_id
  - old_actor
  - new_actor
  - confidence
  - reason
  - cleaned_at
```

## معايير القبول

لا تقبل الأداة النتيجة الجديدة إلا إذا:

1. ✅ ليست فارغة
2. ✅ ليست من القائمة السوداء
3. ✅ ليست وكالة إعلامية
4. ✅ أفضل من القيمة الحالية (أو القيمة الحالية ملوثة)
5. ✅ مستوى الثقة مقبول (>50% في بعض الحالات)

## جدول السجلات

تنشئ الأداة جدولاً خاصاً لتسجيل جميع عمليات التنظيف:

```sql
CREATE TABLE wp_so_cleanup_log (
    id bigint(20) AUTO_INCREMENT PRIMARY KEY,
    event_id bigint(20) NOT NULL,
    old_actor varchar(255),
    new_actor varchar(255),
    confidence int(3),
    reason text,
    source varchar(100),
    cleaned_at datetime DEFAULT CURRENT_TIMESTAMP,
    KEY event_id (event_id),
    KEY cleaned_at (cleaned_at)
);
```

## الأداء

- **حجم الدفعة الافتراضي**: 50 حدث
- **المهلة الزمنية**: 60 ثانية لكل طلب AJAX
- **التأخير بين الدفعات**: 300-500 ميلي ثانية
- **الحد الأقصى للسجل**: 100 إدخال في الواجهة

## التكامل مع المحرك الموحد

تعتمد الأداة بالكامل على:

```php
sod_resolve_actor_final($title, $content, $region)
```

والذي يستخدم:

1. **ActorDecisionEngineV3** (الأولوية القصوى)
2. **Fallback إلى sod_actor_engine_v2** إذا فشل V3
3. **المنطقة** كسياق إضافي للتحليل

## الملفات المضافة

```
includes/
  ├── archive-cleanup-tool.php      # المحرك الأساسي
  ├── archive-cleanup-admin.php     # الواجهة الإدارية
  └── archive-cleanup-cli.php       # أوامر WP-CLI

assets/
  ├── css/
  │   └── cleanup-admin.css         # تنسيقات الواجهة
  └── js/
      └── cleanup-admin.js          # تفاعلات الواجهة
```

## مثال على نتيجة التنظيف

### قبل
```json
{
  "actor_final": "فاعل غير محسوم",
  "actor_v2": "غير معروف",
  "war_data": {
    "actor": "مجهول"
  }
}
```

### بعد
```json
{
  "actor_final": "حزب الله",
  "actor_v2": "حزب الله",
  "actor_final_unified": true,
  "actor_source": "actor_engine_v3",
  "actor_reason": "ذكر صريح في العنوان",
  "actor_confidence": 95,
  "war_data": {
    "actor": "حزب الله",
    "who": "حزب الله",
    "who_primary": "حزب الله"
  },
  "_cleaned_at": "2025-01-15 10:30:00",
  "_cleaned_from": "فاعل غير محسوم"
}
```

## الاستكشاف والأخطاء

### المشكلة: لا تظهر الأحداث الملوثة
**الحل**: تأكد من أن الأحداث من نوع `so_event` ومنشورة

### المشكلة: الفشل في إعادة التحليل
**الحل**: تحقق من وجود دالة `sod_resolve_actor_final`

### المشكلة: بطء المعالجة
**الحل**: قلل حجم الدفعة إلى 10-25 حدث

### المشكلة: ذاكرة غير كافية
**الحل**: استخدم WP-CLI بدلاً من الواجهة الإدارية

## أفضل الممارسات

1. ✅ **اختبر على نسخة احتياطية أولاً**
2. ✅ **ابدأ بدفعات صغيرة** (10-25 حدث)
3. ✅ **راجع السجل** بعد كل دفعة
4. ✅ **استخدم WP-CLI** للمجموعات الكبيرة
5. ✅ **جدد الفهرس** بعد التنظيف الكبير

## الترخيص

جزء من إضافة OSINT-LB PRO
