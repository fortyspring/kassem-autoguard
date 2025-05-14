# Save the README.md content into a file for GitHub
readme_markdown = """
# 🛡️ Kassem AutoGuard

**Kassem AutoGuard** هي إضافة ووردبريس قوية مصممة لتحسين أداء الموقع من خلال تنظيف خيارات autoload، مراقبة الجداول، ومنع الأخطاء القاتلة الناتجة عن الخيارات الزائدة.

---

## 🔍 الميزات الرئيسية

- تحليل شامل لخيار autoload في قاعدة البيانات
- تنبيهات تلقائية عند تجاوز الحجم المسموح
- سجل حذف مفصل مع إمكانية التصدير إلى TXT
- حماية تلقائية من حذف ملفات أو جداول WordPress الأساسية
- دعم كامل للغة العربية وواجهة RTL
- تحديث تلقائي للإضافة (GitHub / WP Repository)
- إعدادات تحكم مرنة لعرض الشعار أو الإشعارات

---

## 🧰 طريقة التنصيب

1. حمّل الإضافة بصيغة ZIP من [صفحة التحميل](https://github.com/fortyspring/kassem-autoguard/archive/refs/heads/main.zip)
2. اذهب إلى لوحة التحكم في WordPress > إضافات > أضف جديد > رفع الإضافة
3. فعّل الإضافة بعد التثبيت وابدأ بالاستخدام من قائمة ⚙️ Kassem AutoGuard

---

## 🖼️ لقطات شاشة (قريبًا)

| الشاشة | الوصف |
|--------|-------|
| 🚧     | سيتم إضافة صور قريبا |

---

## 💬 الدعم الفني

📧 عبر البريد: support@good-press.net  
🌐 عبر GitHub Issues: [فتح تذكرة دعم](https://github.com/fortyspring/kassem-autoguard/issues)

---

## 🪪 معلومات المطور

- الاسم: محمد قاسم  
- الموقع: [good-press.net](https://good-press.net)  
- الترخيص: GPL v2 أو أحدث

> 🚀 تم تطوير هذه الإضافة لمواقع الأخبار العربية التي تحتاج إلى أداء مرتفع وتحكم ذكي في قاعدة البيانات.
"""

# Save it to a file
readme_path = "/mnt/data/README.md"
with open(readme_path, "w", encoding="utf-8") as f:
    f.write(readme_markdown)

readme_path
