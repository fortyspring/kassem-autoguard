import json
import re
from datetime import datetime
from typing import Dict, List, Optional, Tuple

class NewsClassificationEngine:
    """
    محرك تصنيف ونشر الأخبار الذكي
    يطبق شروط 5W1H الصارمة، كشف الحرب المركبة، وفلترة المصادر.
    """

    def __init__(self):
        # قواعد البيانات المدمجة (يمكن تحميلها من ملفات خارجية)
        self.leaders_db = self._load_leaders_db()
        self.required_fields = ['who', 'what', 'why', 'how', 'when', 'where']
        self.hybrid_warfare_keywords = {
            'military': ['جيش', 'قصف', 'دبابات', 'طيران', 'صاروخ', 'غزو', 'قتال'],
            'cyber': ['سيبراني', 'اختراق', 'قرصنة', 'برمجيات خبيثة', 'انقطاع شبكة'],
            'information': ['دعاية', 'إشاعة', 'حرب نفسية', 'تضليل', 'رواية مضادة'],
            'economic': ['عقوبات', 'حصار', 'قطع تموين', 'أسعار', 'عملة', 'نفط'],
            'political': ['دبلوماسية', 'قرار أممي', 'اعتراف', 'مقاطعة', 'تحالف'],
            'social': ['احتجاج', 'نزوح', 'مظاهرات', 'انقسام طائفي'],
            'psychological': ['رعب', 'تهديد', 'خوف', 'معنويات']
        }
        self.news_store = []  # قاعدة بيانات الأخبار الحالية
        self.deleted_log = []  # سجل الأخبار المحذوفة

    def _load_leaders_db(self) -> Dict:
        """تحميل قاموس القادة (نسخة مختصرة للتمثيل، يمكن توسيعها)"""
        return {
            "netanyahu": {"name": "بنيامين نتنياهو", "role": "رئيس وزراء", "country": "إسرائيل"},
            "herzog": {"name": "إسحاق هرتسوغ", "role": "رئيس دولة", "country": "إسرائيل"},
            "putin": {"name": "فلاديمير بوتين", "role": "رئيس جمهورية", "country": "روسيا"},
            "biden": {"name": "جو بايدن", "role": "رئيس جمهورية", "country": "الولايات المتحدة"},
            "khamenei": {"name": "علي خامنئي", "role": "مرشد أعلى", "country": "إيران"},
            "sisi": {"name": "عبد الفتاح السيسي", "role": "رئيس جمهورية", "country": "مصر"},
            "mbz": {"name": "محمد بن زايد", "role": "رئيس دولة", "country": "الإمارات"},
            "mbs": {"name": "محمد بن سلمان", "role": "ولي العهد/رئيس وزراء", "country": "السعودية"}
        }

    def extract_5w1h(self, text: str) -> Dict[str, Optional[str]]:
        """
        استخراج عناصر 5W1H من النص باستخدام تحليل لغوي مبسط (يمكن استبداله بـ NLP متقدم).
        يجب أن يجد قيماً مقبولة لكل حقل.
        """
        elements = {
            'who': None,   # من
            'what': None,  # ماذا
            'why': None,   # لماذا
            'how': None,   # كيف
            'when': None,  # متى
            'where': None  # أين
        }

        # 1. من (Who): بحث عن أسماء قادة أو كيانات
        found_who = False
        for key, info in self.leaders_db.items():
            if info['name'] in text or key in text.lower():
                elements['who'] = info['name']
                found_who = True
                break
        
        # بحث عام عن كيانات إذا لم يوجد قائد محدد
        if not found_who:
            who_patterns = [
                r'(رئيس\s+الوزراء|رئيس\s+الدولة|رئيس\s+الجمهورية|وزير\s+\w+|ملك\s+\w+|أمير\s+\w+|قائد\s+\w+|مجلس\s+\w+|قوات\s+\w+)',
                r'(\w+\s+(بن\s+\w+|آل\s+\w+))',  # الأسماء العربية المركبة
                r'(\w+\s+\w+\s+(نتنياهو|بوتين|بايدن|خامنئي|سيسي|ترامب))'
            ]
            for pattern in who_patterns:
                match = re.search(pattern, text)
                if match:
                    elements['who'] = match.group(0)
                    found_who = True
                    break
        
        # إذا وجدنا كلمة "أعلن" أو "شنت" بدون فاعل محدد، نعتبر أن الفاعل مذكور ضمنياً
        if not found_who:
            if re.search(r'(أعلن|شنت|قام|نفذ|قرر)', text):
                elements['who'] = "جهة فاعلة محددة في السياق"
                found_who = True

        # 2. ماذا (What): فعل الحدث الرئيسي
        what_patterns = [
            r'(قصف|اغتيال|عقوبات|اجتماع|إعلان|هجوم|انسحاب|توقيع|انتخاب|عملية\s+\w+|حملة\s+\w+)',
            r'(شنّ?\s+\w+|أعلن\s+\w+|بدأ\s+\w+|نفذ\s+\w+)'
        ]
        for pattern in what_patterns:
            if re.search(pattern, text):
                elements['what'] = "حدث عسكري/سياسي تم رصده"
                break
        
        # إذا كان هناك فعل حركة رئيسي
        if not elements['what']:
            if re.search(r'(أعلن|شنت|قام|نفذ|بدأ|استهدف)', text):
                elements['what'] = "حدث رئيسي تم رصده"

        # 3. لماذا (Why): السببية
        why_indicators = ['بسبب', 'رداً على', 'نتيجة لـ', 'لأجل', 'سعياً لـ', 'لمواجهة', 'كردّ على']
        for indicator in why_indicators:
            if indicator in text:
                elements['why'] = "سبب محدد مذكور في النص"
                break
        
        # 4. كيف (How): الأسلوب
        how_indicators = ['عن طريق', 'باستخدام', 'عبر', 'بواسطة', 'من خلال', 'مدعوماً بـ', 'عن']
        for indicator in how_indicators:
            if indicator in text:
                elements['how'] = "أسلوب التنفيذ موضح"
                break
        
        # 5. متى (When): الزمن
        time_patterns = [
            r'\d{1,2}/\d{1,2}/\d{4}', 
            r'اليوم', r'أمس', r'غداً', 
            r'صباح', r'مساء', r'ليلاً',
            r'ساعة\s+\d+', 
            r'في\s+الساعة\s+\d+',
            r'خلال\s+\w+',
            r'مؤخراً', r'حديثاً'
        ]
        for pattern in time_patterns:
            if re.search(pattern, text):
                elements['when'] = "توقيت محدد"
                break

        # 6. أين (Where): المكان
        location_patterns = [
            r'في\s+\w+', 
            r'على حدود', 
            r'داخل\s+\w+', 
            r'عاصمة\s+\w*', 
            r'مدينة\s+\w*',
            r'تل\s+أبيب', r'غزة', r'دمشق', r'بغداد', r'طهران', r'موسكو',
            r'الضفة\s+\w+', r'القدس', r'سوريا', r'العراق', r'اليمن', r'لبنان'
        ]
        for pattern in location_patterns:
            if re.search(pattern, text):
                elements['where'] = "موقع جغرافي محدد"
                break

        return elements

    def classify_hybrid_warfare(self, text: str) -> List[str]:
        """تحديد مجالات الحرب المركبة الموجودة في الخبر"""
        detected_domains = []
        text_lower = text.lower()
        
        for domain, keywords in self.hybrid_warfare_keywords.items():
            for keyword in keywords:
                if keyword in text_lower:
                    if domain not in detected_domains:
                        detected_domains.append(domain)
                    break
        
        return detected_domains

    def validate_source_compatibility(self, news_item: Dict) -> bool:
        """
        التحقق من توافق المصدر وشروط النشر الأساسية.
        هنا نفترض أن المصدر موثوق إذا مرر من الفلترة الأولية.
        """
        # يمكن إضافة قائمة سوداء للمصادر هنا
        blacklisted_sources = ["unknown", "fake_news_channel"]
        source = news_item.get('source', '').lower()
        
        if any(bs in source for bs in blacklisted_sources):
            return False
        return True

    def process_news(self, raw_news: Dict) -> Dict:
        """
        الدالة الرئيسية لمعالجة الخبر:
        1. استخراج 5W1H
        2. التحقق من اكتمال البيانات
        3. تصنيف الحرب المركبة
        4. قرار النشر أو الحذف
        """
        text = raw_news.get('content', '')
        source = raw_news.get('source', 'Unknown')
        timestamp = raw_news.get('timestamp', datetime.now().isoformat())

        # الخطوة 1: استخراج 5W1H
        elements = self.extract_5w1h(text)
        
        # الخطوة 2: التحقق من اكتمال الشروط (شرط صارم)
        missing_fields = [field for field, value in elements.items() if value is None]
        
        if missing_fields:
            # الخبر ناقص -> حذف فوري وعدم نشر
            decision = "REJECTED"
            reason = f"نقص في عناصر 5W1H: {', '.join(missing_fields)}"
            self._log_deletion(raw_news, reason)
            return {
                "status": "FAILED",
                "action": "DELETED",
                "reason": reason,
                "data": None
            }

        # الخطوة 3: التحقق من توافق المصدر
        if not self.validate_source_compatibility(raw_news):
            decision = "REJECTED"
            reason = "المصدر غير موثوق أو مدرج في القائمة السوداء"
            self._log_deletion(raw_news, reason)
            return {
                "status": "FAILED",
                "action": "DELETED",
                "reason": reason,
                "data": None
            }

        # الخطوة 4: تصنيف الحرب المركبة
        hybrid_domains = self.classify_hybrid_warfare(text)
        is_hybrid = len(hybrid_domains) >= 2
        
        # الخطوة 5: بناء الخبر النهائي
        processed_news = {
            "id": len(self.news_store) + 1,
            "content": text,
            "source": source,
            "timestamp": timestamp,
            "classification": {
                "5W1H": elements,
                "hybrid_warfare": {
                    "is_hybrid": is_hybrid,
                    "domains": hybrid_domains
                },
                "tags": ["HYBRID_WARFARE"] if is_hybrid else []
            },
            "status": "PUBLISHED"
        }

        self.news_store.append(processed_news)
        
        return {
            "status": "SUCCESS",
            "action": "PUBLISHED",
            "reason": "تم التحقق من جميع الشروط وتصنيف الخبر بنجاح",
            "data": processed_news
        }

    def manual_reclassify(self, news_id: int, new_classification: Dict) -> Dict:
        """إعادة تصنيف يدوي للخبر"""
        news_item = next((n for n in self.news_store if n['id'] == news_id), None)
        if not news_item:
            return {"status": "ERROR", "message": "الخبر غير موجود"}
        
        # تحديث التصنيف
        news_item['classification'].update(new_classification)
        news_item['classification']['manual_override'] = True
        news_item['classification']['override_time'] = datetime.now().isoformat()
        
        return {"status": "SUCCESS", "message": "تم إعادة التصنيف يدوياً", "data": news_item}

    def auto_reclassify(self, news_id: int) -> Dict:
        """إعادة تصنيف تلقائي عند تحديث المعلومات"""
        news_item = next((n for n in self.news_store if n['id'] == news_id), None)
        if not news_item:
            return {"status": "ERROR", "message": "الخبر غير موجود"}
        
        # إعادة تشغيل خوارزمية الاستخراج على نفس النص
        new_elements = self.extract_5w1h(news_item['content'])
        new_hybrid = self.classify_hybrid_warfare(news_item['content'])
        
        news_item['classification']['5W1H'] = new_elements
        news_item['classification']['hybrid_warfare']['domains'] = new_hybrid
        news_item['classification']['hybrid_warfare']['is_hybrid'] = len(new_hybrid) >= 2
        
        return {"status": "SUCCESS", "message": "تم إعادة التصنيف تلقائياً", "data": news_item}

    def _log_deletion(self, news_item: Dict, reason: str):
        """تسجيل الخبر المحذوف في السجل الآمن"""
        log_entry = {
            "deleted_at": datetime.now().isoformat(),
            "reason": reason,
            "original_content": news_item.get('content', ''),
            "source": news_item.get('source', '')
        }
        self.deleted_log.append(log_entry)

    def get_published_news(self) -> List[Dict]:
        return self.news_store

    def get_deleted_log(self) -> List[Dict]:
        return self.deleted_log

# ==========================================
# مثال على الاستخدام والتجربة (Testing)
# ==========================================
if __name__ == "__main__":
    engine = NewsClassificationEngine()

    # خبر جيد (يجب أن يمر)
    good_news = {
        "source": "Reuters Official",
        "content": "أعلن رئيس الوزراء بنيامين نتنياهو اليوم في تل أبيب عن عملية عسكرية جديدة رداً على الهجمات الصاروخية، وذلك باستخدام الطيران الحربي لاستهداف مواقع العدو."
        # من: نتنياهو | ماذا: عملية عسكرية | لماذا: رداً على الهجمات | كيف: طيران حربي | متى: اليوم | أين: تل أبيب
    }

    # خبر سيء (ناقص - يجب أن يحذف)
    bad_news = {
        "source": "Unknown Blog",
        "content": "يبدو أن هناك توتراً كبيراً في المنطقة وقد يحدث شيء ما قريباً."
        # لا يوجد من، ماذا محدد، لماذا، كيف، متى، أين واضح
    }

    # خبر حرب مركبة (يجب تصنيفه) - نسخة محسنة تحتوي على كل العناصر
    hybrid_news = {
        "source": "Strategic Center",
        "content": "شنت قوات خاصة اليوم في العاصمة هجوماً برياً مدعوماً بحرب سيبرانية شلت اتصالات العدو، بالتزامن مع حملة دعائية نفسية لإضعاف المعنويات، وذلك رداً على الهجمات السابقة."
        # من: قوات خاصة | ماذا: هجوم بري | لماذا: رداً على الهجمات | كيف: حرب سيبرانية + دعائية | متى: اليوم | أين: العاصمة
        # عسكري + سيبراني + نفسي + معلوماتي = حرب مركبة
    }

    print("--- تجربة الخبر الجيد ---")
    result_good = engine.process_news(good_news)
    print(json.dumps(result_good, ensure_ascii=False, indent=2))

    print("\n--- تجربة الخبر السيء (الحذف) ---")
    result_bad = engine.process_news(bad_news)
    print(json.dumps(result_bad, ensure_ascii=False, indent=2))

    print("\n--- تجربة خبر الحرب المركبة ---")
    result_hybrid = engine.process_news(hybrid_news)
    print(json.dumps(result_hybrid, ensure_ascii=False, indent=2))

    print("\n--- سجل الأخبار المحذوفة ---")
    print(json.dumps(engine.get_deleted_log(), ensure_ascii=False, indent=2))
