# دليل إعداد MyFatoorah لـ Smart MyFatoorah Gateway

هذا الدليل يوضح **كل الخطوات** لإنشاء API Token وWebhook بشكل صحيح، مع **الصلاحيات والأحداث** المطلوبة حتى تعمل الإضافة بالكامل.

إضافة WordPress: **Smart MyFatoorah Gateway**  
نقطة الـ Webhook في الموقع (نفس رابط الإضافة الرسمية):

```text
https://YOUR-DOMAIN.com/?wc-api=myfatoorah_webhook
```

**مهم:** عطّل إضافة MyFatoorah الرسمية واترك Smart فقط، حتى لا يتنازع الاثنان على نفس الرابط. اترك الرابط كما هو في بوابة MyFatoorah إن كان مضبوطًا مسبقًا.

> استبدل `YOUR-DOMAIN.com` بنطاق موقعك الحقيقي (HTTPS إلزامي في الوضع المباشر).

---

## 1) ما الذي تستخدمه الإضافة؟

| الوظيفة | Endpoint | مطلوب؟ |
|---------|----------|--------|
| اكتشاف طرق الدفع (QPay / بطاقة / محافظ) | `POST /v2/InitiatePayment` | نعم |
| نموذج البطاقة المضمّن (CardView) | `POST /v2/InitiateSession` | نعم (إذا فعّلت Embedded) |
| دفع QPay / البطاقة المضمّنة | `POST /v2/ExecutePayment` | نعم |
| استعلام حالة الدفع (V2) | `POST /v2/GetPaymentStatus` | نعم |
| دفع Hosted (بطاقة / Apple Pay / Google Pay) | `POST /v3/payments` | نعم |
| استعلام حالة الدفع (V3) | `GET /v3/payments/{PaymentId}` | نعم |
| الاسترجاع من ووكومرس | `POST /v2/MakeRefund` | نعم (إذا فعّلت Refunds) |
| تسجيل نطاق Apple Pay | `POST /v2/RegisterApplePayDomain` | اختياري (Apple Pay) |

**أحداث Webhook V2 التي تعالجها الإضافة:**

| Event.Code | Event.Name | الاستخدام |
|------------|------------|----------|
| `1` | `PAYMENT_STATUS_CHANGED` | تأكيد/فشل الدفع تلقائيًا |
| `2` | `REFUND_STATUS_CHANGED` | تأكيد الاسترجاع في ووكومرس |

> أحداث أخرى (مثل Balance / Supplier / Recurring) **غير مستخدمة** حاليًا ويمكن تجاهلها.

---

## 2) إنشاء حساب Demo (للاختبار)

1. سجّل حساب تجريبي من: [registertest.myfatoorah.com](https://registertest.myfatoorah.com/en/)
2. أكمل التسجيل (غالبًا دولة الكويت في الديمو).
3. راسل `tech@myfatoorah.com` لتفعيل الحساب التجريبي والميزات المطلوبة.
4. سجّل الدخول إلى بوابة الديمو ثم أكمل الخطوات أدناه.

**روابط مفيدة:**

| البيئة | بوابة الإدارة | API Base |
|--------|---------------|----------|
| Test / Demo | `https://demo.myfatoorah.com` | `https://apitest.myfatoorah.com` |
| Live قطر | `https://qa.myfatoorah.com` | `https://api-qa.myfatoorah.com` |
| Live الكويت / عام | `https://portal.myfatoorah.com` | `https://api.myfatoorah.com` |
| Live السعودية | `https://sa.myfatoorah.com` | `https://api-sa.myfatoorah.com` |
| Live الإمارات | `https://ae.myfatoorah.com` | `https://api-ae.myfatoorah.com` |
| Live مصر | `https://eg.myfatoorah.com` | `https://api-eg.myfatoorah.com` |

> لكل دولة حساب Live لها **Token مستقل**. في إعدادات الإضافة اختر **Merchant country** المطابقة لنفس التوكن.

---

## 3) إنشاء API Token (خطوة بخطوة)

1. ادخل بوابة MyFatoorah (Demo أو Live حسب البيئة).
2. من القائمة الجانبية: **Integration Settings → API Key**.
3. اضغط **Add** لإنشاء مفتاح جديد.
4. عبّئ الحقول:
   - **Name:** مثال `Smart WooCommerce Gateway`
   - **Expiry Date:** تاريخ بعيد أو حسب سياسة شركتك
   - **Active Status:** Active
   - **Permissions:** راجع القسم التالي وفعّل كل المطلوب
5. اضغط **Create**.
6. انسخ التوكن فورًا (يظهر كمفتاح سري).
7. الصقه في ووردبريس:
   - **WooCommerce → Settings → Payments → Smart MyFatoorah → API token**
8. فعّل **Test mode** إذا كان التوكن من الديمو، وألغِه للتوكن المباشر.
9. احفظ الإعدادات ثم اضغط **Test MyFatoorah connection**.

### ملاحظات مهمة عن التوكن

- الحد الأقصى غالبًا **5 مفاتيح** لكل حساب.
- لا تعطّل المستخدم الذي أنشأ التوكن؛ تعطيله يعطّل المفتاح.
- إذا استدعيت Endpoint بدون صلاحية ستحصل على:  
  `401 — The token does not have the required permissions!`
- الحساب متعدد الدول يحتاج Token لكل دولة مفعّلة.

---

## 4) صلاحيات API Token التي يجب تفعيلها

واجهة البوابة قد تعرض أسماء صلاحيات مجمّعة مثل:

- **Super Rules** (موصى به للتكامل الكامل)
- **Create Payments**
- **Update Payments**
- صلاحيات مخصّصة حسب Endpoint

### التوصية للإضافة

لضمان عمل كل الميزات بدون أخطاء صلاحيات:

1. الأفضل: فعّل **Super Rules** (أو Full / All payment permissions إن وُجدت).
2. أو فعّل يدويًا كل ما يخص الدفع + الاستعلام + الاسترجاع + الجلسات.

### خريطة الصلاحيات حسب ميزات الإضافة

| ميزة في الإضافة | Endpoints المستخدمة | صلاحية مطلوبة (مفهوميًا) |
|-----------------|---------------------|---------------------------|
| اختبار الاتصال + اكتشاف الطرق | `InitiatePayment` | Create/Read payments أو Initiate Payment |
| QPay | `InitiatePayment` + `ExecutePayment` + `GetPaymentStatus` | Create Payments + Payment inquiry |
| Embedded CardView | `InitiateSession` + `ExecutePayment` + `GetPaymentStatus` | Embedded / Session + Create Payments |
| Hosted Card / Apple Pay / Google Pay | `v3/payments` + `GET v3/payments/{id}` | Create Payments (V3) + Inquiry |
| المصالحة التلقائية / Callback | `GetPaymentStatus` و/أو `GET v3/payments/{id}` | Payment status inquiry |
| استرجاع ووكومرس | `MakeRefund` | Refunds / Make Refund |
| تسجيل نطاق Apple Pay | `RegisterApplePayDomain` | Apple Pay / Domain registration |

### قائمة تحقق سريعة للصلاحيات

- [ ] إنشاء المدفوعات / Create Payments
- [ ] تنفيذ الدفع / Execute Payment
- [ ] بدء الجلسة / Initiate Session (للـ Embedded)
- [ ] بدء طرق الدفع / Initiate Payment
- [ ] استعلام حالة الدفع / Get Payment Status
- [ ] مدفوعات V3 / Create & Get V3 Payments
- [ ] الاسترجاع / Make Refund
- [ ] تسجيل نطاق Apple Pay / Register Apple Pay Domain *(إن ستستخدم Apple Pay)*
- [ ] أو ببساطة: **Super Rules**

> إذا ظهرت رسالة نقص صلاحيات بعد الحفظ، عدّل نفس المفتاح من البوابة وأضف الصلاحية الناقصة ثم أعد الاختبار.

---

## 5) إعداد Webhook V2 (خطوة بخطوة)

1. في بوابة MyFatoorah: **Integration Settings → Webhook Settings**.
2. اختر **Webhook V2** (مهم: الإضافة مصممة لـ V2 وليس V1).
3. ضع رابط الإضافة حرفيًا:

```text
https://YOUR-DOMAIN.com/?wc-api=myfatoorah_webhook
```

4. عطّل إضافة MyFatoorah الرسمية إن كانت مفعّلة (نفس الرابط) واترك Smart فقط.
5. فعّل التوقيع الآمن / **Secure Key** (إلزامي للإضافة).
6. انسخ **Webhook Secret / Secure Key**.
7. في ووردبريس الصقه في:
   - **Smart MyFatoorah → Webhook secret**
8. تأكد أن **Webhook** مفعّل في إعدادات الإضافة.
9. احفظ الإعدادات في البوابة وفي ووردبريس.

### الأحداث التي يجب تفعيلها

| الحدث | هل يجب تفعيله؟ | لماذا |
|-------|----------------|-------|
| **PAYMENT_STATUS_CHANGED** | **نعم — إلزامي** | تحديث الطلب عند نجاح/فشل الدفع حتى لو لم يعد العميل من صفحة الدفع |
| **REFUND_STATUS_CHANGED** | **نعم — إذا فعّلت الاسترجاع** | إنشاء استرجاع ووكومرس بعد موافقة ماي فاتورة |
| BALANCE_TRANSFERRED | لا | غير مستخدم |
| SUPPLIER_STATUS_CHANGED | لا | غير مستخدم |
| RECURRING_UPDATES | لا | غير مستخدم |
| DISPUTE_STATUS_CHANGED | لا | غير مستخدم |
| SUPPLIER_UPDATE_REQUEST_CHANGED | لا | غير مستخدم |

### كيف تتحقق الإضافة من التوقيع؟

- الهيدر: `MyFatoorah-Signature`
- الخوارزمية: HMAC-SHA256 ثم Base64
- المفتاح: نفس Webhook Secret
- للإضافة لن تقبل Webhook إذا كان السر فارغًا أو التوقيع غير صحيح

---

## 6) إعدادات إضافية في بوابة ماي فاتورة (موصى بها)

### طرق الدفع

حسب احتياجك فعّل في لوحة MyFatoorah:

- [ ] **QPay / Qatar Debit Cards** *(لحسابات قطر فقط)*
- [ ] **Visa / Mastercard**
- [ ] **Apple Pay** *(اختياري)*
- [ ] **Google Pay** *(اختياري)*

> الإضافة تظهر QPay فقط إذا كانت **دولة التاجر = قطر** وكانت الطريقة مفعّلة في الحساب.  
> Apple Pay / Google Pay يظهران فقط إذا أبلغت ماي فاتورة أنهما مفعّلان.

### Apple Pay (إن لزم)

1. استلم ملف التحقق من دعم ماي فاتورة:
   `apple-developer-merchantid-domain-association`
2. انشره على:

```text
https://YOUR-DOMAIN.com/.well-known/apple-developer-merchantid-domain-association
```

3. من إعدادات الإضافة اضغط **Register this domain with MyFatoorah**.
4. سجّل النطاق مرة للـ Test ومرة للـ Live (بتوكن البيئة المناسبة).

### الاسترجاع

1. فعّل صلاحية **Make Refund** للتوكن.
2. فعّل حدث **REFUND_STATUS_CHANGED** في Webhook.
3. في ووردبريس فعّل **Allow refund requests from WooCommerce orders**.

---

## 7) إعداد الإضافة داخل ووكومرس

1. **WooCommerce → Settings → Payments → Smart MyFatoorah**
2. فعّل البوابة.
3. أدخل:
   - Test mode (نعم/لا)
   - Merchant country (قطر لحساب قطر)
   - API token
   - Webhook secret
4. اختياري:
   - Embedded card form
   - Invoice line items
   - Invoice expiry
   - Automatic reconciliation
   - Refunds
5. احفظ.
6. اضغط **Test MyFatoorah connection**.
7. نفّذ طلب اختبار حقيقي (بطاقة تجريبية في الديمو).

### رابط الـ Callback

الإضافة تنشئ Callback تلقائيًا عبر WooCommerce API:

```text
https://YOUR-DOMAIN.com/?wc-api=smart_myfatoorah_callback
```

لا تحتاج إدخاله يدويًا في البوابة؛ يُرسل مع كل عملية دفع في `CallBackUrl` / `Redirection`.

---

## 8) قائمة تحقق نهائية قبل الإطلاق Live

### التوكن

- [ ] توكن Live لدولة الحساب الصحيحة
- [ ] صلاحيات Create/Execute/Inquire/Session/V3/Refund (أو Super Rules)
- [ ] Test mode = Off في الإضافة
- [ ] Merchant country مطابقة للتوكن
- [ ] اختبار الاتصال ناجح

### Webhook

- [ ] Webhook V2
- [ ] الرابط الصحيح `/?wc-api=myfatoorah_webhook`
- [ ] الإضافة الرسمية معطّلة (Smart فقط)
- [ ] Secure Key مفعّل ومنسوخ للإضافة
- [ ] `PAYMENT_STATUS_CHANGED` مفعّل
- [ ] `REFUND_STATUS_CHANGED` مفعّل (إن لزم)
- [ ] الموقع على HTTPS عام (ليس localhost)

### الدفع

- [ ] Visa/Mastercard مفعّلة
- [ ] QPay مفعّل *(قطر فقط)*
- [ ] Apple/Google Pay مفعّلان إن كنت ستعرضهما
- [ ] طلب تجريبي ناجح يحدّث حالة الطلب إلى Processing/Completed
- [ ] ملاحظة الطلب تحتوي Invoice ID / Payment ID

### الاسترجاع (اختياري)

- [ ] طلب استرجاع من الطلب في ووكومرس
- [ ] وصول webhook `REFUND_STATUS_CHANGED`
- [ ] إنشاء استرجاع ووكومرس بعد التأكيد

---

## 9) أعطال شائعة وحلولها

| المشكلة | السبب المحتمل | الحل |
|---------|---------------|------|
| `token does not have the required permissions` | صلاحية ناقصة | عدّل API Key وأضف الصلاحية / Super Rules |
| Webhook يُرفض (401) | سر خاطئ أو غير موجود | انسخ Secure Key من البوابة إلى الإضافة |
| الطلب يبقى Pending رغم الدفع | Webhook يذهب للإضافة الرسمية أو السر فارغ / Callback فشل | عطّل الرسمية، تأكد من السر والرابط `/?wc-api=myfatoorah_webhook`، أو أعد التحقق يدويًا من الطلب |
| QPay لا يظهر | دولة التاجر ليست قطر أو QPay غير مفعّل | اضبط Merchant country = Qatar وفعّل QPay في البوابة |
| Apple/Google Pay لا يظهران | غير مفعّلين في الحساب | فعّلهما في MyFatoorah ثم أعد اختبار الاتصال |
| فشل من localhost | MyFatoorah لا تقبل localhost كـ Callback | استخدم نطاق HTTPS عام أو نفق مثل ngrok |
| الاسترجاع لا يكتمل في ووكومرس | لم يُفعَّل حدث الاسترجاع | فعّل `REFUND_STATUS_CHANGED` وصلاحية Make Refund |

---

## 10) ملخص سريع (نسخ سريع)

**API Token permissions:**  
فعّل **Super Rules** أو كل صلاحيات الدفع + الاستعلام + Initiate Session + Make Refund + Register Apple Pay Domain.

**Webhook V2 events:**  
- `PAYMENT_STATUS_CHANGED`  
- `REFUND_STATUS_CHANGED`  

**Webhook URL:**

```text
https://YOUR-DOMAIN.com/?wc-api=myfatoorah_webhook
```

**مهم:** عطّل الإضافة الرسمية واترك Smart فقط على نفس الرابط.

**في الإضافة:**  
API token + Webhook secret + Merchant country الصحيحة + HTTPS في Live.

- [ ] استخدم **Live token** منفصل عن الديمو ولا تشارك السر علنًا
- [ ] فعّل **Webhook V2** + Secure Key + أحداث الدفع والاسترجاع
- [ ] الموقع على **HTTPS** (الإضافة ترفض Live بدون HTTPS)
- [ ] عطّل **Debug log** في الإنتاج إلا عند التشخيص المؤقت
- [ ] احذف مجلد `myfatoorah-woocommerce/` المرجعي من نسخة الإنتاج إن وُجد (مرجعي فقط وغير مستخدم)
- [ ] اختبر: دفع ناجح، فشل، webhook، استرجاع
- [ ] تأكد أن صلاحيات التوكن محدودة بما تحتاجه الإضافة (أو Super Rules بحساب موثوق)

*آخر تحديث متوافق مع Smart MyFatoorah Gateway 1.0.5*
